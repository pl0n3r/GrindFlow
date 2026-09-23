<?php

declare(strict_types=1);

namespace GrindFlow\Infrastructure\Storage;

/**
 * Issues a short-lived browser upload intent without exposing provider credentials.
 */
final class DirectUploadIntentIssuer
{
    public const DEFAULT_TTL_SECONDS = 900;

    public function __construct(
        private readonly DirectUploadStorage $storage,
        private readonly DirectUploadTokenCipher $tokens,
        private readonly DirectUploadObjectKeys $keys,
    ) {
    }

    /**
     * @return array{
     *   url: string,
     *   headers: array<string, string>,
     *   upload_token: string,
     *   expires_at: string
     * }
     */
    public function issue(
        string $organizationId,
        string $userId,
        string $filename,
        string $mimeType,
        int $byteSize,
        ?int $now = null,
    ): array {
        if (!$this->storage->available() || !$this->tokens->configured()) {
            throw new \RuntimeException('Direct upload is not configured.');
        }

        $issuedAt = $now ?? time();
        $expiresAt = $issuedAt + self::DEFAULT_TTL_SECONDS;
        $storageKey = $this->keys->staging($organizationId);

        $token = $this->tokens->issue(
            $organizationId,
            $userId,
            $this->storage->disk(),
            $storageKey,
            $filename,
            $mimeType,
            $byteSize,
            self::DEFAULT_TTL_SECONDS,
            $issuedAt,
        );
        $upload = $this->storage->temporaryUpload($storageKey, $mimeType, $expiresAt);
        $url = $upload['url'] ?? null;
        $headers = $upload['headers'] ?? null;
        if (!is_string($url) || $url === '' || !is_array($headers)) {
            throw new \RuntimeException('Direct upload storage returned an invalid temporary upload contract.');
        }

        $normalizedHeaders = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || $name === '' || !is_string($value)) {
                throw new \RuntimeException('Direct upload storage returned an invalid temporary upload header.');
            }
            $normalizedHeaders[$name] = $value;
        }

        return [
            'url' => $url,
            'headers' => $normalizedHeaders,
            'upload_token' => $token,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expiresAt),
        ];
    }
}
