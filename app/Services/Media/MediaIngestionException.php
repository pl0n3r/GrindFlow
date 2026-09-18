<?php

namespace App\Services\Media;

use RuntimeException;

class MediaIngestionException extends RuntimeException
{
    public static function sourceMissing(): self
    {
        return new self('source_missing');
    }

    public static function sourceUnreadable(): self
    {
        return new self('source_unreadable');
    }

    public static function sizeMismatch(): self
    {
        return new self('source_size_mismatch');
    }

    public static function unsupportedMime(): self
    {
        return new self('unsupported_mime_type');
    }

    public static function storageWriteFailed(): self
    {
        return new self('storage_write_failed');
    }
}
