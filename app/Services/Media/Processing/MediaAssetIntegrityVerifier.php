<?php

namespace App\Services\Media\Processing;

use App\Models\MediaAsset;
use App\Models\MediaBlob;
use Illuminate\Support\Facades\Storage;

class MediaAssetIntegrityVerifier
{
    /**
     * @return array{sha256: string, byte_size: int, mime_type: string|null}
     */
    public function verify(MediaAsset $asset): array
    {
        $blob = $asset->blob()->first();

        if ($blob instanceof MediaBlob === false) {
            throw MediaProcessingException::blobMissing();
        }

        $filesystem = Storage::disk($blob->storage_disk);

        if ($filesystem->exists($blob->storage_key) === false) {
            throw MediaProcessingException::sourceMissing();
        }

        $stream = $filesystem->readStream($blob->storage_key);

        if ($stream === null) {
            throw MediaProcessingException::sourceUnreadable();
        }

        try {
            $hash = hash_init('sha256');
            $byteSize = 0;

            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);

                if ($chunk === false) {
                    throw MediaProcessingException::sourceUnreadable();
                }

                if ($chunk === '') {
                    continue;
                }

                $byteSize += strlen($chunk);
                hash_update($hash, $chunk);
            }

            $sha256 = hash_final($hash);
        } finally {
            fclose($stream);
        }

        if (
            hash_equals($blob->sha256, $sha256) === false
            || $byteSize !== $blob->byte_size
        ) {
            throw MediaProcessingException::integrityMismatch();
        }

        return [
            'sha256' => $sha256,
            'byte_size' => $byteSize,
            'mime_type' => $blob->mime_type,
        ];
    }
}
