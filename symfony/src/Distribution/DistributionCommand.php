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
    }
}
