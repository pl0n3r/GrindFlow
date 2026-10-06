<?php

declare(strict_types=1);

const RECOVERY_MAGIC = "GFRCV1\n";
const RECOVERY_CHUNK_BYTES = 1048576;

final class RecoveryCryptoFailure extends RuntimeException
{
}

function reject(string $message): never
{
    throw new RecoveryCryptoFailure($message);
}

function requireSodiumRuntime(): void
{
    $required = [
        'sodium_crypto_secretstream_xchacha20poly1305_init_push',
        'sodium_crypto_secretstream_xchacha20poly1305_push',
        'sodium_crypto_secretstream_xchacha20poly1305_init_pull',
        'sodium_crypto_secretstream_xchacha20poly1305_pull',
    ];

    if (!extension_loaded('sodium')) {
        reject('libsodium extension is unavailable.');
    }

    foreach ($required as $function) {
        if (!function_exists($function)) {
            reject('libsodium secretstream runtime is unavailable.');
        }
    }
}

function recoveryKey(): string
{
    $encoded = getenv('GF_RECOVERY_KEY_B64');
    if (!is_string($encoded) || $encoded === '') {
        reject('recovery key is unavailable.');
    }

    $key = base64_decode($encoded, true);
    if (
        !is_string($key)
        || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES
    ) {
        reject('recovery key is invalid.');
    }

    return $key;
}

/** @param resource $stream */
function readExact($stream, int $length): string
{
    $value = '';
    while (strlen($value) < $length) {
        $chunk = fread($stream, $length - strlen($value));
        if ($chunk === false || $chunk === '') {
            reject('encrypted recovery bundle is truncated.');
        }
        $value .= $chunk;
    }

    return $value;
}

function safeInput(string $path): void
{
    $metadata = @lstat($path);
    if (
        $metadata === false
        || ($metadata['mode'] & 0170000) !== 0100000
        || is_link($path)
    ) {
        reject('recovery input is unavailable or unsafe.');
    }
}

/** @return resource */
function openPrivateOutput(string $path)
{
    if (file_exists($path) || is_link($path)) {
        reject('recovery output path already exists.');
    }

    $stream = @fopen($path, 'x+b');
    if ($stream === false) {
        reject('recovery output could not be created.');
    }

    if (@chmod($path, 0600) === false) {
        fclose($stream);
        @unlink($path);
        reject('recovery output could not be secured.');
    }

    return $stream;
}

function encryptBundle(string $inputPath, string $outputPath, string $key): void
{
    safeInput($inputPath);
    $input = @fopen($inputPath, 'rb');
    if ($input === false) {
        reject('recovery input could not be opened.');
    }

    $output = openPrivateOutput($outputPath);
    $success = false;

    try {
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        if (fwrite($output, RECOVERY_MAGIC.$header) !== strlen(RECOVERY_MAGIC.$header)) {
            reject('encrypted recovery bundle could not be written.');
        }

        $current = fread($input, RECOVERY_CHUNK_BYTES);
        if ($current === false) {
            reject('recovery input could not be read.');
        }

        do {
            $next = fread($input, RECOVERY_CHUNK_BYTES);
            if ($next === false) {
                reject('recovery input could not be read.');
            }

            $final = $next === '' && feof($input);
            $tag = $final
                ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
            $ciphertext = sodium_crypto_secretstream_xchacha20poly1305_push(
                $state,
                $current,
                '',
                $tag,
            );
            $frame = pack('N', strlen($ciphertext)).$ciphertext;

            if (fwrite($output, $frame) !== strlen($frame)) {
                reject('encrypted recovery bundle could not be written.');
            }

            $current = $next;
        } while (!$final);

        if (!fflush($output)) {
            reject('encrypted recovery bundle could not be flushed.');
        }

        $success = true;
    } finally {
        fclose($input);
        fclose($output);
        if (!$success) {
            @unlink($outputPath);
        }
    }
}

function decryptBundle(string $inputPath, string $outputPath, string $key): void
{
    safeInput($inputPath);
    $input = @fopen($inputPath, 'rb');
    if ($input === false) {
        reject('encrypted recovery bundle could not be opened.');
    }

    $output = openPrivateOutput($outputPath);
    $success = false;

    try {
        if (readExact($input, strlen(RECOVERY_MAGIC)) !== RECOVERY_MAGIC) {
            reject('encrypted recovery bundle format is invalid.');
        }

        $header = readExact(
            $input,
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES,
        );
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
        $sawFinal = false;

        while (!feof($input)) {
            $prefix = fread($input, 4);
            if ($prefix === false) {
                reject('encrypted recovery bundle could not be read.');
            }
            if ($prefix === '') {
                break;
            }
            if (strlen($prefix) !== 4) {
                reject('encrypted recovery bundle is truncated.');
            }

            $length = unpack('Nlength', $prefix)['length'] ?? 0;
            $minimum = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
            $maximum = RECOVERY_CHUNK_BYTES + $minimum;
            if (!is_int($length) || $length < $minimum || $length > $maximum) {
                reject('encrypted recovery bundle frame is invalid.');
            }

            $ciphertext = readExact($input, $length);
            $pulled = sodium_crypto_secretstream_xchacha20poly1305_pull(
                $state,
                $ciphertext,
                '',
            );
            if ($pulled === false) {
                reject('encrypted recovery bundle authentication failed.');
            }

            [$plaintext, $tag] = $pulled;
            if (fwrite($output, $plaintext) !== strlen($plaintext)) {
                reject('decrypted recovery bundle could not be written.');
            }

            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                $sawFinal = true;
                $extra = fread($input, 1);
                if ($extra === false) {
                    reject('encrypted recovery bundle could not be read.');
                }
                if ($extra !== '' || !feof($input)) {
                    reject('encrypted recovery bundle has trailing data.');
                }
                break;
            }

            if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE) {
                reject('encrypted recovery bundle tag is invalid.');
            }
        }

        if (!$sawFinal) {
            reject('encrypted recovery bundle final authentication tag is missing.');
        }

        if (!fflush($output)) {
            reject('decrypted recovery bundle could not be flushed.');
        }

        $success = true;
    } finally {
        fclose($input);
        fclose($output);
        if (!$success) {
            @unlink($outputPath);
        }
    }
}

$key = '';
try {
    requireSodiumRuntime();

    if ($argc !== 4 || !in_array($argv[1], ['encrypt', 'decrypt'], true)) {
        reject('usage: recovery-secretstream.php encrypt|decrypt INPUT OUTPUT.');
    }

    $key = recoveryKey();
    if ($argv[1] === 'encrypt') {
        encryptBundle($argv[2], $argv[3], $key);
    } else {
        decryptBundle($argv[2], $argv[3], $key);
    }

    fwrite(STDOUT, "RECOVERY_SECRETSTREAM_OK\n");
} catch (RecoveryCryptoFailure $exception) {
    fwrite(STDERR, 'ERROR: '.$exception->getMessage()."\n");
    exit(2);
} catch (Throwable) {
    fwrite(STDERR, "ERROR: recovery crypto operation failed.\n");
    exit(2);
} finally {
    if ($key !== '') {
        sodium_memzero($key);
    }
}
