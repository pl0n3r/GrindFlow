<?php

namespace App\Services\Media\Connections;

use App\Services\Media\Connectors\MediaConnectorException;
use JsonException;

final readonly class GoogleDriveScanCursor
{
    private const VERSION = 1;

    private const MODE_BOOTSTRAP = 'bootstrap';

    private const MODE_CHANGES = 'changes';

    private function __construct(
        public string $mode,
        public string $pageToken,
        public ?string $startPageToken = null,
    ) {}

    public static function bootstrap(
        string $startPageToken,
        ?string $filePageToken = null,
    ): self {
        if ($startPageToken === '') {
            throw MediaConnectorException::requestFailed();
        }

        return new self(
            self::MODE_BOOTSTRAP,
            $filePageToken ?? '',
            $startPageToken,
        );
    }

    public static function changes(string $pageToken): self
    {
        if ($pageToken === '') {
            throw MediaConnectorException::requestFailed();
        }

        return new self(
            self::MODE_CHANGES,
            $pageToken,
        );
    }

    public static function decode(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $data = json_decode(
                $value,
                true,
                8,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            throw MediaConnectorException::requestFailed();
        }

        if (
            is_array($data) === false
            || ($data['v'] ?? null) !== self::VERSION
            || is_string($data['mode'] ?? null) === false
            || is_string($data['page_token'] ?? null) === false
        ) {
            throw MediaConnectorException::requestFailed();
        }

        $mode = $data['mode'];
        $pageToken = $data['page_token'];

        if ($mode === self::MODE_CHANGES) {
            return self::changes($pageToken);
        }

        if ($mode !== self::MODE_BOOTSTRAP) {
            throw MediaConnectorException::requestFailed();
        }

        $startPageToken = $data['start_page_token'] ?? null;

        if (is_string($startPageToken) === false || $startPageToken === '') {
            throw MediaConnectorException::requestFailed();
        }

        return self::bootstrap(
            $startPageToken,
            $pageToken === '' ? null : $pageToken,
        );
    }

    public function isBootstrap(): bool
    {
        return $this->mode === self::MODE_BOOTSTRAP;
    }

    public function encode(): string
    {
        try {
            return json_encode([
                'v' => self::VERSION,
                'mode' => $this->mode,
                'page_token' => $this->pageToken,
                'start_page_token' => $this->startPageToken,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw MediaConnectorException::requestFailed();
        }
    }
}
