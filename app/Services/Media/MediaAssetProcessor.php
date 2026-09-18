<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Models\MediaBlob;
use Illuminate\Support\Facades\Storage;

class MediaAssetProcessor
{
    public const VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public function process(MediaAsset $asset): array
    {
        $blob = $asset->blob;

        if ($blob instanceof MediaBlob === false) {
            throw MediaProcessingException::blobMissing();
        }

        $disk = Storage::disk($blob->storage_disk);

        if ($disk->exists($blob->storage_key) === false) {
            throw MediaProcessingException::objectMissing();
        }

        if ($disk->size($blob->storage_key) !== $blob->byte_size) {
            throw MediaProcessingException::sizeMismatch();
        }

        $mimeType = $blob->mime_type;

        if (is_string($mimeType) === false || $mimeType === '') {
            throw MediaProcessingException::unsupportedMime();
        }

        $kind = $this->kind($mimeType);

        if ($kind === null) {
            throw MediaProcessingException::unsupportedMime();
        }

        return [
            'version' => self::VERSION,
            'profile' => 'probe_v1',
            'media_kind' => $kind,
            'mime_type' => $mimeType,
            'byte_size' => $blob->byte_size,
            'sha256' => $blob->sha256,
        ];
    }

    private function kind(string $mimeType): ?string
    {
        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        }

        if (str_starts_with($mimeType, 'video/')) {
            return 'video';
        }

        return null;
    }
}
