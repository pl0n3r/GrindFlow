<?php

namespace App\Services\Media;

use RuntimeException;

class MediaProcessingException extends RuntimeException
{
    public static function blobMissing(): self
    {
        return new self('processing_blob_missing');
    }

    public static function objectMissing(): self
    {
        return new self('processing_object_missing');
    }

    public static function sizeMismatch(): self
    {
        return new self('processing_size_mismatch');
    }

    public static function unsupportedMime(): self
    {
        return new self('processing_unsupported_mime');
    }
}
