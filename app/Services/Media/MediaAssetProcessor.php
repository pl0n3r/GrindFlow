<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Models\MediaBlob;
use Illuminate\Support\Facades\Storage;

class MediaAssetProcessor
{
    public const VERSION = 2;

    public const FFPROBE_VERSION = 3;

    public const FFMPEG_VERSION = 4;

    public const FFPROBE_FFMPEG_VERSION = 5;

    public function __construct(
        private readonly FfprobeMediaInspector $ffprobe,
        private readonly FfmpegMediaDerivativeGenerator $derivatives,
    ) {}

    public function currentVersion(): int
    {
        $ffprobeEnabled = $this->ffprobe->enabled();
        $ffmpegEnabled = $this->derivatives->enabled();

        return match (true) {
            $ffprobeEnabled && $ffmpegEnabled => self::FFPROBE_FFMPEG_VERSION,
            $ffprobeEnabled => self::FFPROBE_VERSION,
            $ffmpegEnabled => self::FFMPEG_VERSION,
            default => self::VERSION,
        };
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
        $ffmpegEnabled = $this->ffmpegEnabledForVersion($processorVersion);
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
            'derivative_profile' => $ffmpegEnabled
                ? FfmpegMediaDerivativeGenerator::PROFILE
                : 'disabled',
            'derivatives' => $ffmpegEnabled
                ? $this->derivatives->generate($blob)
                : [],
        ];
    }

    private function ffprobeEnabledForVersion(int $processorVersion): bool
    {
        return match ($processorVersion) {
            self::VERSION, self::FFMPEG_VERSION => false,
            self::FFPROBE_VERSION, self::FFPROBE_FFMPEG_VERSION => true,
            default => throw MediaProcessingException::invalidProcessorVersion(),
        };
    }

    private function ffmpegEnabledForVersion(int $processorVersion): bool
    {
        return match ($processorVersion) {
            self::VERSION, self::FFPROBE_VERSION => false,
            self::FFMPEG_VERSION, self::FFPROBE_FFMPEG_VERSION => true,
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
