<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Models\MediaBlob;
use Illuminate\Support\Facades\Storage;

class MediaAssetProcessor
{
    public const VERSION = 2;

    public const FFPROBE_VERSION = 3;

    public function __construct(
        private readonly FfprobeMediaInspector $ffprobe,
    ) {}

    public function currentVersion(): int
    {
        return $this->ffprobe->enabled()
            ? self::FFPROBE_VERSION
            : self::VERSION;
    }

    /**
     * @return array<string, mixed>
     */
    public function process(
        MediaAsset $asset,
        ?int $processorVersion = null,
    ): array {
        $processorVersion ??= $this->currentVersion();
        $ffprobeEnabled = $this->ffprobeEnabledForVersion($processorVersion);
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
            'version' => $processorVersion,
            'profile' => 'probe_v'.$processorVersion,
            'media_kind' => $kind,
            'mime_type' => $mimeType,
            'byte_size' => $blob->byte_size,
            'sha256' => $blob->sha256,
            'technical_probe' => $ffprobeEnabled
                ? 'ffprobe'
                : 'disabled',
            'technical_metadata' => $ffprobeEnabled
                ? $this->ffprobe->inspect($blob)
                : null,
        ];
    }

    private function ffprobeEnabledForVersion(int $processorVersion): bool
    {
        return match ($processorVersion) {
            self::VERSION => false,
            self::FFPROBE_VERSION => true,
            default => throw MediaProcessingException::invalidProcessorVersion(),
        };
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
