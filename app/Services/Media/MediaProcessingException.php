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

    public static function probeFailed(): self
    {
        return new self('processing_probe_failed');
    }

    public static function probeInvalidOutput(): self
    {
        return new self('processing_probe_invalid_output');
    }

    public static function probeTimedOut(): self
    {
        return new self('processing_probe_timed_out');
    }

    public static function derivativeFailed(): self
    {
        return new self('processing_derivative_failed');
    }

    public static function derivativeTimedOut(): self
    {
        return new self('processing_derivative_timed_out');
    }

    public static function derivativeInvalidOutput(): self
    {
        return new self('processing_derivative_invalid_output');
    }

    public static function derivativeWriteFailed(): self
    {
        return new self('processing_derivative_write_failed');
    }

    public static function invalidProcessorVersion(): self
    {
        return new self('processing_invalid_processor_version');
    }
}
