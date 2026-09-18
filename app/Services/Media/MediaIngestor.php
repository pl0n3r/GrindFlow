<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Models\MediaBlob;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class MediaIngestor
{
    public function __construct(
        private readonly MediaProcessingCoordinator $processing,
    ) {}

    public function ingest(UploadedFile $file, User $actor): MediaAsset
    {
        $organizationId = app(TenantContext::class)->organizationId();

        if ($organizationId === null) {
            throw new AuthorizationException('Tenant context is required to ingest media.');
        }

        $realPath = $file->getRealPath();

        if ($realPath === false) {
            throw new RuntimeException('Uploaded media is not readable.');
        }

        $sha256 = hash_file('sha256', $realPath);

        if ($sha256 === false) {
            throw new RuntimeException('Unable to hash uploaded media.');
        }

        $byteSize = $file->getSize();

        if ($byteSize === false) {
            throw new RuntimeException('Unable to determine uploaded media size.');
        }

        $disk = (string) config('grindflow.media.disk', config('filesystems.default', 'local'));
        $storageKey = sprintf(
            'organizations/%s/blobs/%s/%s',
            $organizationId,
            substr($sha256, 0, 2),
            $sha256,
        );

        $blob = MediaBlob::query()
            ->where('sha256', $sha256)
            ->first();

        if ($blob === null) {
            $stream = fopen($realPath, 'rb');

            if ($stream === false) {
                throw new RuntimeException('Unable to open uploaded media stream.');
            }

            try {
                $stored = Storage::disk($disk)->put($storageKey, $stream);
            } finally {
                fclose($stream);
            }

            if (! $stored) {
                throw new RuntimeException('Unable to persist uploaded media.');
            }

            $blob = MediaBlob::query()->firstOrCreate(
                ['sha256' => $sha256],
                [
                    'storage_disk' => $disk,
                    'storage_key' => $storageKey,
                    'byte_size' => $byteSize,
                    'mime_type' => $file->getMimeType(),
                    'metadata' => [
                        'client_mime_type' => $file->getClientMimeType(),
                    ],
                ],
            );
        }

        $canonicalAsset = MediaAsset::query()
            ->where('media_blob_id', $blob->getKey())
            ->whereNull('duplicate_of')
            ->oldest('created_at')
            ->first();

        $asset = MediaAsset::query()->create([
            'media_blob_id' => $blob->getKey(),
            'duplicate_of' => $canonicalAsset?->getKey(),
            'ingested_by_user_id' => $actor->getKey(),
            'original_filename' => $file->getClientOriginalName(),
            'source_type' => 'manual_upload',
            'source_ref' => null,
            'status' => $canonicalAsset === null
                ? MediaAsset::STATUS_READY
                : MediaAsset::STATUS_DUPLICATE,
            'metadata' => [
                'original_extension' => $file->getClientOriginalExtension(),
            ],
        ]);

        return $this->processing->queue($asset, $actor);
    }
}
