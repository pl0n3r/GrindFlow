<?php

declare(strict_types=1);

namespace GrindFlow\Distribution;

use InvalidArgumentException;

final readonly class DistributionCommand
{
    public function __construct(
        public string $organizationId,
        public string $idempotencyKey,
        public string $message,
        public ?string $link = null,
        public ?string $mediaPath = null,
        public ?string $mediaMime = null,
        public ?string $mediaSha256 = null,
    ) {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $this->organizationId)) {
            throw new InvalidArgumentException('Distribution organization id must be a UUID.');
        }

        if ($this->idempotencyKey === '' || mb_strlen($this->idempotencyKey) > 160) {
            throw new InvalidArgumentException('Distribution idempotency key must contain 1-160 characters.');
        }

        if (trim($this->message) === '' || mb_strlen($this->message) > 5000) {
            throw new InvalidArgumentException('Distribution message must contain 1-5000 characters.');
        }

        if ($this->link !== null) {
            if (mb_strlen($this->link) > 2048) {
                throw new InvalidArgumentException('Distribution link is too long.');
            }

            $parts = parse_url($this->link);
            $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
            $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';

            if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
                throw new InvalidArgumentException('Distribution link must be an absolute HTTP(S) URL.');
            }
        }

        $media = [$this->mediaPath, $this->mediaMime, $this->mediaSha256];
        $configured = count(array_filter($media, static fn (?string $value): bool => $value !== null));
        if ($configured !== 0 && $configured !== count($media)) {
            throw new InvalidArgumentException('Distribution media fields must be provided together.');
        }

        if ($configured === count($media)) {
            if ($this->mediaPath === '' || str_contains($this->mediaPath, "\0")) {
                throw new InvalidArgumentException('Distribution media path is invalid.');
            }
            if (!in_array($this->mediaMime, ['image/jpeg', 'image/png'], true)) {
                throw new InvalidArgumentException('Distribution media MIME is not supported.');
            }
            if (preg_match('/^[0-9a-f]{64}$/D', strtolower((string) $this->mediaSha256)) !== 1) {
                throw new InvalidArgumentException('Distribution media SHA-256 is invalid.');
            }
            if ($this->link !== null) {
                throw new InvalidArgumentException('Distribution media and link cannot be sent together.');
            }
        }
    }

    public function hasMedia(): bool
    {
        return $this->mediaPath !== null;
    }
}
