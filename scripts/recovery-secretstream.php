<?php

declare(strict_types=1);

const RECOVERY_MAGIC = "GFRCV1\n";
const RECOVERY_CHUNK_BYTES = 1048576;

function fail(string $message, int $code = 2): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit($code);
}

function requireSodiumRuntime(): void
{
    $required = [
        'sodium_crypto_secretstream_xchacha20poly1305_init_push',
        'sodium_crypto_secretstream_xchacha20poly1305_push',
        'sodium_crypto_secretstream_xchacha20poly1305_init_pull',
        'sodium_crypto_secretstream_xchacha20poly1305_pull',
    ];

    if (! extension_loaded('sodium')) {
        fail('libsodium extension is unavailable.');
    }

    foreach ($required as $function) {
        if (! function_exists($function)) {
            fail('libsodium secretstream runtime is unavailable.');
        }
    }
}

function recoveryKey(): string
{
    $encoded = getenv('GF_RECOVERY_KEY_B64');
    if (! is_string($encoded) || $encoded === '') {
        fail('recovery key is unavailable.');
    }

    $key = base64_decode($encoded, true);
    if (
        ! is_string($key)
        || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES
    ) {
        fail('recovery key is invalid.');
    }

    return $key;
}

function readExact($stream, int $length): string
{
    $value = '';
    while (strlen($value) < $length) {
        $chunk = fread($stream, $length - strlen($value));
        if ($chunk === false || $chunk === '') {
            fail('encrypted recovery bundle is truncated.');
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
        fail('recovery input is unavailable or unsafe.');
    }
}

function openPrivateOutput(string $path)
{
    if (file_exists($path) || is_link($path)) {
        fail('recovery output path already exists.');
    }

    $stream = @fopen($path, 'x+b');
    if ($stream === false) {
        fail('recovery output could not be created.');
    }

    if (@chmod($path, 0600) === false) {
        fclose($stream);
        @unlink($path);
        fail('recovery output could not be secured.');
    }

    return $stream;
}

function encryptBundle(string $inputPath, string $outputPath, string $key): void
{
    safeInput($inputPath);
    $input = @fopen($inputPath, 'rb');
    if ($input === false) {
        fail('recovery input could not be opened.');
    }

    $output = openPrivateOutput($outputPath);

    try {
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        if (fwrite($output, RECOVERY_MAGIC.$header) === false) {
            fail('encrypted recovery bundle could not be written.');
        }

        $current = fread($input, RECOVERY_CHUNK_BYTES);
        if ($current === false) {
            fail('recovery input could not be read.');
        }

        if ($current === '' && feof($input)) {
            $ciphertext = sodium_crypto_secretstream_xchacha20poly1305_push(
                $state,
                '',
                '',
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL,
            );
            fwrite($output, pack('N', strlen($ciphertext)).$ciphertext);
        } else {
            while (true) {
                $next = fread($input, RECOVERY_CHUNK_BYTES);
                if ($next === false) {
                    fail('recovery input could not be read.');
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

                if (fwrite($output, pack('N', strlen($ciphertext)).$ciphertext) === false) {
                    fail('encrypted recovery bundle could not be written.');
                }

                if ($final) {
                    break;
                }

                $current = $next;
            }
        }

        if (! fflush($output)) {
            fail('encrypted recovery bundle could not be flushed.');
        }
    } catch (Throwable) {
        fail('recovery encryption failed.');
    } finally {
        fclose($input);
        fclose($output);
    }
}

function decryptBundle(string $inputPath, string $outputPath, string $key): void
{
    safeInput($inputPath);
    $input = @fopen($inputPath, 'rb');
    if ($input === false) {
        fail('encrypted recovery bundle could not be opened.');
    }

    $output = openPrivateOutput($outputPath);
    $success = false;

    try {
        if (readExact($input, strlen(RECOVERY_MAGIC)) !== RECOVERY_MAGIC) {
            fail('encrypted recovery bundle format is invalid.');
        }

        $header = readExact(
            $input,
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES,
        );
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
        $sawFinal = false;

        while (! feof($input)) {
            $prefix = fread($input, 4);
            if ($prefix === false) {
                fail('encrypted recovery bundle could not be read.');
            }
            if ($prefix === '') {
                break;
            }
            if (strlen($prefix) !== 4) {
                fail('encrypted recovery bundle is truncated.');
            }

            $length = unpack('Nlength', $prefix)['length'] ?? 0;
            $minimum = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
            $maximum = RECOVERY_CHUNK_BYTES + $minimum;
            if ($length < $minimum || $length > $maximum) {
                fail('encrypted recovery bundle frame is invalid.');
            }

            $ciphertext = readExact($input, $length);
            $pulled = sodium_crypto_secretstream_xchacha20poly1305_pull(
                $state,
                $ciphertext,
                '',
            );
            if ($pulled === false) {
                fail('encrypted recovery bundle authentication failed.');
            }

            [$plaintext, $tag] = $pulled;
            if (fwrite($output, $plaintext) === false) {
                fail('decrypted recovery bundle could not be written.');
            }

            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                $sawFinal = true;
                if (fread($input, 1) !== '') {
                    fail('encrypted recovery bundle has trailing data.');
                }
                break;
            }

            if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE) {
                fail('encrypted recovery bundle tag is invalid.');
            }
        }

        if (! $sawFinal) {
            fail('encrypted recovery bundle final authentication tag is missing.');
        }

        if (! fflush($output)) {
            fail('decrypted recovery bundle could not be flushed.');
        }

        $success = true;
    } catch (Throwable) {
        fail('recovery decryption failed.');
    } finally {
        fclose($input);
        fclose($output);
        if (! $success) {
            @unlink($outputPath);
        }
    }
}

requireSodiumRuntime();

if ($argc !== 4 || ! in_array($argv[1], ['encrypt', 'decrypt'], true)) {
    fail('usage: recovery-secretstream.php encrypt|decrypt INPUT OUTPUT.');
}

$key = recoveryKey();
if ($argv[1] === 'encrypt') {
    encryptBundle($argv[2], $argv[3], $key);
} else {
    decryptBundle($argv[2], $argv[3], $key);
}

fwrite(STDOUT, "RECOVERY_SECRETSTREAM_OK\n");
