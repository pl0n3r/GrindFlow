<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Models\MediaBlob;
use App\Models\MobileUploadGrantUse;
use App\Models\OperationalProfile;
use App\Support\Security\MobileUploadGrant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;

final readonly class GuestMobileUploadIngestor
{
    public function __construct(
        private MobileUploadGrant $grants,
        private TenantContext $tenantContext,
        private VaultOwnershipTriage $triage,
    ) {}

    /**
     * @param  list<mixed>  $files
     * @return list<MediaAsset>
     */
    public function ingest(
        string $token,
        string $expectedOrganizationId,
        array $files,
        ?string $profileId,
        int $now,
    ): array {
        $grant = $this->grants->validate(
            $token,
            $expectedOrganizationId,
            $now,
        );

        $prepared = $this->prepareFiles(
            $files,
            $grant['max_files'],
            $grant['max_bytes'],
        );

        return $this->tenantContext->runWithinGuestOrganization(
            $grant['organization_id'],
            function () use ($grant, $prepared, $profileId, $now): array {
                $profile = $this->resolveProfile($profileId);

                return DB::transaction(function () use (
                    $grant,
                    $prepared,
                    $profile,
                    $now,
                ): array {
                    $grantUse = $this->consumeGrant(
                        $grant['nonce'],
                        count($prepared),
                        array_sum(array_column($prepared, 'byte_size')),
                        $now,
                    );

                    $assets = [];

                    foreach ($prepared as $file) {
                        $asset = $this->persistFile($file, $grantUse);

                        if ($profile instanceof OperationalProfile) {
                            $asset->forceFill([
                                'profile_id' => $profile->getKey(),
                            ])->save();

                            $assets[] = $asset->refresh();

                            continue;
                        }

                        $this->triage->queueAmbiguous($asset);
                        $assets[] = $asset;
                    }

                    return $assets;
                });
            },
        );
    }

    /**
     * @param  list<mixed>  $files
     * @return list<array{
     *     file: UploadedFile,
     *     real_path: string,
     *     sha256: string,
     *     byte_size: int,
     *     mime_type: string
     * }>
     */
    private function prepareFiles(
        array $files,
        int $maxFiles,
        int $maxBytes,
    ): array {
        if ($files === [] || count($files) > $maxFiles) {
            throw ValidationException::withMessages([
                'media' => 'Guest upload file count exceeds the grant.',
            ]);
        }

        /** @var array<int, string> $allowed */
        $allowed = config('grindflow.media.allowed_mimetypes', []);
        $prepared = [];
        $totalBytes = 0;

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || $file->isValid() === false) {
                throw ValidationException::withMessages([
                    'media' => 'Guest upload contains an invalid file.',
                ]);
            }

            $realPath = $file->getRealPath();
            $byteSize = $file->getSize();
            $mimeType = $file->getMimeType();

            if (
                $realPath === false
                || $byteSize === false
                || $byteSize < 1
                || ! is_string($mimeType)
                || ! in_array($mimeType, $allowed, true)
            ) {
                throw ValidationException::withMessages([
                    'media' => 'Guest upload media failed validation.',
                ]);
            }

            $sha256 = hash_file('sha256', $realPath);

            if ($sha256 === false) {
                throw new RuntimeException('Unable to hash guest upload media.');
            }

            $totalBytes += $byteSize;

            if ($totalBytes > $maxBytes) {
                throw ValidationException::withMessages([
                    'media' => 'Guest upload byte limit exceeds the grant.',
                ]);
            }

            $prepared[] = [
                'file' => $file,
                'real_path' => $realPath,
                'sha256' => $sha256,
                'byte_size' => $byteSize,
                'mime_type' => $mimeType,
            ];
        }

        return $prepared;
    }

    private function resolveProfile(?string $profileId): ?OperationalProfile
    {
        if ($profileId === null) {
            return null;
        }

        $profile = OperationalProfile::query()->find($profileId);

        if (! $profile instanceof OperationalProfile) {
            throw ValidationException::withMessages([
                'profile_id' => 'Guest upload profile is invalid for this tenant.',
            ]);
        }

        return $profile;
    }

    private function consumeGrant(
        string $nonce,
        int $fileCount,
        int $byteCount,
        int $now,
    ): MobileUploadGrantUse {
        try {
            return MobileUploadGrantUse::query()->create([
                'nonce' => $nonce,
                'file_count' => $fileCount,
                'byte_count' => $byteCount,
                'consumed_at' => CarbonImmutable::createFromTimestampUTC($now),
            ]);
        } catch (QueryException $exception) {
            $sqlState = $exception->errorInfo[0] ?? null;

            if (in_array($sqlState, ['23000', '23505'], true)) {
                throw new LogicException(
                    'Guest upload grant has already been consumed.',
                    previous: $exception,
                );
            }

            throw $exception;
        }
    }

    /**
     * @param  array{
     *     file: UploadedFile,
     *     real_path: string,
     *     sha256: string,
     *     byte_size: int,
     *     mime_type: string
     * }  $prepared
     */
    private function persistFile(
        array $prepared,
        MobileUploadGrantUse $grantUse,
    ): MediaAsset {
        $organizationId = $this->tenantContext->organizationId();

        if ($organizationId === null || $this->tenantContext->actorId() !== null) {
            throw new LogicException('Guest upload requires tenant-only context.');
        }

        $blob = MediaBlob::query()
            ->where('sha256', $prepared['sha256'])
            ->first();

        if (! $blob instanceof MediaBlob) {
            $disk = (string) config(
                'grindflow.media.disk',
                config('filesystems.default', 'local'),
            );
            $storageKey = sprintf(
                'organizations/%s/blobs/%s/%s',
                $organizationId,
                substr($prepared['sha256'], 0, 2),
                $prepared['sha256'],
            );
            $stream = fopen($prepared['real_path'], 'rb');

            if ($stream === false) {
                throw new RuntimeException('Unable to open guest upload media.');
            }

            try {
                $stored = Storage::disk($disk)->put($storageKey, $stream);
            } finally {
                fclose($stream);
            }

            if (! $stored) {
                throw new RuntimeException('Unable to persist guest upload media.');
            }

            $blob = MediaBlob::query()->firstOrCreate(
                ['sha256' => $prepared['sha256']],
                [
                    'storage_disk' => $disk,
                    'storage_key' => $storageKey,
                    'byte_size' => $prepared['byte_size'],
                    'mime_type' => $prepared['mime_type'],
                    'metadata' => [
                        'ingestion_mode' => 'guest_mobile_upload',
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

        return MediaAsset::query()->create([
            'media_blob_id' => $blob->getKey(),
            'duplicate_of' => $canonicalAsset?->getKey(),
            'ingested_by_user_id' => null,
            'original_filename' => $prepared['file']->getClientOriginalName(),
            'source_type' => 'guest_mobile_upload',
            'source_ref' => null,
            'status' => $canonicalAsset === null
                ? MediaAsset::STATUS_READY
                : MediaAsset::STATUS_DUPLICATE,
            'metadata' => [
                'upload_mode' => 'guest_mobile',
                'grant_use_id' => (string) $grantUse->getKey(),
            ],
        ]);
    }
}
