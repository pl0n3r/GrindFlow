<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Models\MediaBlob;
use App\Models\MediaIngestion;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class FilesystemMediaIngestor
{
    public function ingest(MediaIngestion $ingestion): MediaAsset
    {
        $source = Storage::disk($ingestion->source_disk);

        if ($source->exists($ingestion->source_key) === false) {
            throw MediaIngestionException::sourceMissing();
        }

        $sourceStream = $source->readStream($ingestion->source_key);

        if ($sourceStream === null) {
            throw MediaIngestionException::sourceUnreadable();
        }

        $temporary = tmpfile();

        if ($temporary === false) {
            fclose($sourceStream);

            throw new RuntimeException('Unable to create a temporary ingestion stream.');
        }

        try {
            $hash = hash_init('sha256');
            $byteSize = 0;

            while (! feof($sourceStream)) {
                $chunk = fread($sourceStream, 1024 * 1024);

                if ($chunk === false) {
                    throw MediaIngestionException::sourceUnreadable();
                }

                if ($chunk === '') {
                    continue;
                }

                $byteSize += strlen($chunk);
                hash_update($hash, $chunk);

                if (fwrite($temporary, $chunk) === false) {
                    throw MediaIngestionException::sourceUnreadable();
                }
            }

            $sha256 = hash_final($hash);

            if ($ingestion->byte_size !== null && $byteSize !== $ingestion->byte_size) {
                throw MediaIngestionException::sizeMismatch();
            }

            $mimeType = $this->resolveMimeType($ingestion, $temporary);

            if ($this->mimeAllowed($mimeType) === false) {
                throw MediaIngestionException::unsupportedMime();
            }

            $blob = MediaBlob::query()
                ->where('sha256', $sha256)
                ->first();

            if ($blob === null) {
                $targetDisk = (string) config(
                    'grindflow.media.disk',
                    config('filesystems.default', 'local'),
                );
                $targetKey = sprintf(
                    'organizations/%s/blobs/%s/%s',
                    $ingestion->organization_id,
                    substr($sha256, 0, 2),
                    $sha256,
                );

                rewind($temporary);

                if (Storage::disk($targetDisk)->put($targetKey, $temporary) === false) {
                    throw MediaIngestionException::storageWriteFailed();
                }

                $blob = MediaBlob::query()->firstOrCreate(
                    ['sha256' => $sha256],
                    [
                        'storage_disk' => $targetDisk,
                        'storage_key' => $targetKey,
                        'byte_size' => $byteSize,
                        'mime_type' => $mimeType,
                        'metadata' => [
                            'ingestion_mode' => 'filesystem_job',
                            'integrity' => 'sha256_verified',
                        ],
                    ],
                );
            }

            $canonicalAsset = MediaAsset::query()
                ->where('media_blob_id', $blob->getKey())
                ->whereNull('duplicate_of')
                ->oldest('created_at')
                ->first();

            $rawMetadata = $ingestion->getAttribute('metadata');
            $metadata = is_array($rawMetadata) ? $rawMetadata : [];

            $asset = MediaAsset::query()->create([
                'media_blob_id' => $blob->getKey(),
                'duplicate_of' => $canonicalAsset?->getKey(),
                'ingested_by_user_id' => $ingestion->requested_by_user_id,
                'original_filename' => $ingestion->original_filename,
                'source_type' => $ingestion->source_type,
                'source_ref' => $ingestion->source_ref,
                'status' => $canonicalAsset === null
                    ? MediaAsset::STATUS_READY
                    : MediaAsset::STATUS_DUPLICATE,
                'metadata' => array_merge($metadata, [
                    'ingestion_id' => (string) $ingestion->getKey(),
                    'ingestion_mode' => 'filesystem_job',
                ]),
            ]);

            if ($ingestion->delete_source_after_ingest) {
                $source->delete($ingestion->source_key);
            }

            return $asset;
        } finally {
            fclose($sourceStream);
            fclose($temporary);
        }
    }

    /**
     * @param  resource  $temporary
     */
    private function resolveMimeType(
        MediaIngestion $ingestion,
        $temporary,
    ): ?string {
        $metadata = stream_get_meta_data($temporary);
        $temporaryPath = $metadata['uri'] ?? null;

        if (is_string($temporaryPath) && $temporaryPath !== '') {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo !== false) {
                try {
                    $detected = finfo_file($finfo, $temporaryPath);

                    if (
                        is_string($detected)
                        && $detected !== ''
                        && $detected !== 'application/octet-stream'
                    ) {
                        return $detected;
                    }
                } finally {
                    finfo_close($finfo);
                }
            }
        }

        $storedMime = Storage::disk($ingestion->source_disk)
            ->mimeType($ingestion->source_key);

        if (
            is_string($storedMime)
            && $storedMime !== ''
            && $storedMime !== 'application/octet-stream'
        ) {
            return $storedMime;
        }

        return $ingestion->mime_type;
    }

    private function mimeAllowed(?string $mimeType): bool
    {
        if ($mimeType === null) {
            return false;
        }

        /** @var array<int, string> $allowed */
        $allowed = config('grindflow.media.allowed_mimetypes', []);

        return in_array($mimeType, $allowed, true);
    }
}
