<?php

namespace App\Services\Media\Connectors;

use RuntimeException;

class MediaConnectorException extends RuntimeException
{
    public function __construct(
        string $safeCode,
        public readonly bool $needsReconnect = false,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($safeCode);
    }

    public static function unauthorized(): self
    {
        return new self('connector_unauthorized', true);
    }

    public static function rateLimited(?int $retryAfterSeconds = null): self
    {
        return new self(
            'connector_rate_limited',
            false,
            $retryAfterSeconds,
        );
    }

    public static function requestFailed(): self
    {
        return new self('connector_request_failed');
    }

    public static function oauthNotConfigured(): self
    {
        return new self('connector_oauth_not_configured');
    }

    public static function refreshUnavailable(): self
    {
        return new self('connector_refresh_unavailable', true);
    }

    public static function refreshRejected(): self
    {
        return new self('connector_refresh_rejected', true);
    }

    public static function downloadFailed(): self
    {
        return new self('connector_download_failed');
    }

    public static function fileTooLarge(): self
    {
        return new self('connector_file_too_large');
    }

    public static function stagingFailed(): self
    {
        return new self('connector_staging_failed');
    }
}
