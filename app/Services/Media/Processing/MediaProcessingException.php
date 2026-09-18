<?php

namespace App\Services\Media\Processing;

use RuntimeException;

class MediaProcessingException extends RuntimeException
{
    public static function blobMissing(): self
    {
        return new self('processing_blob_missing');
    }

    public static function sourceMissing(): self
    {
        return new self('processing_source_missing');
    }

    public static function sourceUnreadable(): self
    {
        return new self('processing_source_unreadable');
    }

    public static function integrityMismatch(): self
    {
        return new self('processing_integrity_mismatch');
    }
}
