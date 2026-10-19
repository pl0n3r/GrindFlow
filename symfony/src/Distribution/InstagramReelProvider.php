<?php

declare(strict_types=1);

namespace GrindFlow\Distribution;

use InvalidArgumentException;
use JsonException;
use Throwable;

final readonly class InstagramReelProvider implements DistributionProvider
{
    public function __construct(
        private InstagramReelConfiguration $configuration,
        private InstagramReelTransport $transport,
    ) {}

    public function assertAvailableFor(string $organizationId): void
    {
        $this->configuration->assertAvailableFor($organizationId);
    }

    public function destinationId(): string
    {
        return $this->configuration->destinationId();
    }

    public function publish(DistributionCommand $command): DistributionOutcome
    {
        $this->assertAvailableFor($command->organizationId);
        $this->assertVerifiedPrivateVideo($command);

        if (mb_strlen($command->message) > 2200) {
            throw DistributionProviderException::rejected();
        }

        $version = $this->configuration->graphVersion();
        $userId = $this->configuration->destinationId();
        $token = $this->configuration->token();

        try {
            $creation = $this->decode(
                $this->transport->createContainer($version, $userId, $token, $command->message),
            );
            $container = $this->validId($creation['id'] ?? null);
            $uri = $creation['uri'] ?? null;
            if (!is_string($uri) || !self::isOfficialUploadUri($uri)) {
                throw DistributionProviderException::ambiguous();
            }

            $uploaded = $this->decode($this->transport->uploadVideo(
                $uri, $token, (string) $command->mediaPath, (string) $command->mediaMime,
            ));
            if (($uploaded['success'] ?? null) !== true) {
                throw DistributionProviderException::ambiguous();
            }
            // Son lecturas del contenedor ya creado, no reenvíos de vídeo.
            $finished = false;
            for ($attempt = 0; $attempt < 3; ++$attempt) {
                $status = $this->decode($this->transport->containerStatus($version, $container, $token));
                if (($status['status_code'] ?? null) === 'FINISHED') {
                    $finished = true;
                    break;
                }
                if (!in_array($status['status_code'] ?? null, ['IN_PROGRESS', 'PROCESSING'], true)) {
                    throw DistributionProviderException::ambiguous();
                }
            }
            if (!$finished) {
                throw DistributionProviderException::ambiguous();
            }
            $published = $this->decode(
                $this->transport->publishContainer($version, $userId, $container, $token),
            );

            return new DistributionOutcome($this->validId($published['id'] ?? null));
        } catch (DistributionProviderException $e) {
            throw $e;
        } catch (Throwable) {
            throw DistributionProviderException::ambiguous();
        }
    }

    private function assertVerifiedPrivateVideo(DistributionCommand $command): void
    {
        $path = $command->mediaPath;
        if ($command->link !== null || !is_string($path)
            || !in_array($command->mediaMime, ['video/mp4', 'video/quicktime'], true)
            || $path === '' || str_contains($path, "\0")
            || is_link($path) || !is_file($path) || !is_readable($path)) {
            throw DistributionProviderException::rejected();
        }

        // El vídeo solo puede proceder del Vault autorizado (ruta canónica).
        $actual = realpath($path);
        $root = realpath($this->configuration->vaultRoot());
        if ($actual === false || $root === false
            || !str_starts_with($actual, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            throw DistributionProviderException::rejected();
        }

        $size = @filesize($actual);
        // Cota conservadora del piloto; evita buffers gigantes en shared hosting.
        if (!is_int($size) || $size < 1 || $size > 32 * 1024 * 1024) {
            throw DistributionProviderException::rejected();
        }
        try {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($actual);
            $digest = hash_file('sha256', $actual);
        } catch (Throwable) {
            throw DistributionProviderException::rejected();
        }
        if (!is_string($mime) || $mime !== $command->mediaMime
            || !is_string($digest) || !hash_equals(strtolower((string) $command->mediaSha256), $digest)) {
            throw DistributionProviderException::rejected();
        }
    }

    /** @param array{status:int,headers:array<string,string>,body:string} $response
     * @return array<string,mixed>
     */
    private function decode(array $response): array
    {
        $code = $response['status'];
        if ($code === 401 || $code === 403) {
            throw DistributionProviderException::authentication();
        }
        if ($code === 429) {
            $retry = $response['headers']['retry-after'] ?? null;
            throw DistributionProviderException::rateLimited(
                is_string($retry) && preg_match('/^\d{1,5}$/D', $retry) ? (int) $retry : 300,
            );
        }
        if ($code < 200 || $code >= 300) {
            throw $code >= 500 ? DistributionProviderException::ambiguous()
                : DistributionProviderException::rejected();
        }
        try {
            $data = json_decode($response['body'], true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw DistributionProviderException::ambiguous();
        }
        if (!is_array($data) || array_is_list($data)) {
            throw DistributionProviderException::ambiguous();
        }
        return $data;
    }

    private function validId(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^\d{1,32}$/D', $value) !== 1) {
            throw DistributionProviderException::ambiguous();
        }
        return $value;
    }

    public static function isOfficialUploadUri(string $uri): bool
    {
        $u = parse_url($uri);
        return is_array($u)
            && ($u['scheme'] ?? '') === 'https'
            && ($u['host'] ?? '') === 'rupload.facebook.com'
            && !isset($u['port'], $u['user'], $u['pass'], $u['fragment'], $u['query'])
            && isset($u['path'])
            && preg_match('#^/ig-api-upload/v\d{1,3}\.\d{1,3}/\d{1,32}$#D', (string) $u['path']) === 1;
    }
}
