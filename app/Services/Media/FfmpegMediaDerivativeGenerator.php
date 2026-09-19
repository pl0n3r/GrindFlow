<?php

namespace App\Services\Media;

use App\Models\MediaBlob;
use Illuminate\Support\Facades\Storage;

class FfmpegMediaDerivativeGenerator
{
    public const PROFILE = 'thumbnail_v1';

    public const THUMBNAIL_PROFILE = 'thumbnail_v1';

    public const PREVIEW_PROFILE = 'preview_v1';

    public const PROFILE_SET = 'thumbnail_v1+preview_v1';

    public const MIME_TYPE = 'image/webp';

    public const PREVIEW_MIME_TYPE = 'video/mp4';

    public function __construct(
        private readonly FfmpegCommandRunner $runner,
    ) {}

    public function enabled(): bool
    {
        return (bool) config(
            'grindflow.media.ffmpeg.enabled',
            false,
        );
    }

    public function profile(bool $includePreview): string
    {
        return $includePreview
            ? self::PROFILE_SET
            : self::THUMBNAIL_PROFILE;
    }

    /**
     * @return array<string, array<string, int|string>>
     */
    public function generate(
        MediaBlob $blob,
        bool $includePreview = false,
    ): array {
        $disk = Storage::disk($blob->storage_disk);
        $source = $disk->readStream($blob->storage_key);

        if ($source === null) {
            throw MediaProcessingException::objectMissing();
        }

        $input = tmpfile();

        if ($input === false) {
            fclose($source);

            throw MediaProcessingException::derivativeFailed();
        }

        $thumbnailPath = $this->temporaryPath('grindflow-thumb-');
        $previewPath = $includePreview
            ? $this->temporaryPath('grindflow-preview-')
            : null;

        try {
            if (stream_copy_to_stream($source, $input) === false) {
                throw MediaProcessingException::derivativeFailed();
            }

            if (fflush($input) === false) {
                throw MediaProcessingException::derivativeFailed();
            }

            $inputMetadata = stream_get_meta_data($input);
            $inputPath = $inputMetadata['uri'] ?? null;

            if (is_string($inputPath) === false || $inputPath === '') {
                throw MediaProcessingException::derivativeFailed();
            }

            $this->runner->run(
                $this->thumbnailCommand($inputPath, $thumbnailPath),
                $this->timeoutSeconds(),
            );

            if (is_string($previewPath)) {
                $this->runner->run(
                    $this->previewCommand($inputPath, $previewPath),
                    $this->timeoutSeconds(),
                );
            }

            $derivatives = [
                'thumbnail' => $this->persistArtifact(
                    $blob,
                    $thumbnailPath,
                    self::THUMBNAIL_PROFILE,
                    self::MIME_TYPE,
                    'webp',
                ),
            ];

            if (is_string($previewPath)) {
                $derivatives['preview'] = $this->persistArtifact(
                    $blob,
                    $previewPath,
                    self::PREVIEW_PROFILE,
                    self::PREVIEW_MIME_TYPE,
                    'mp4',
                );
            }

            return $derivatives;
        } finally {
            fclose($source);
            fclose($input);

            foreach ([$thumbnailPath, $previewPath] as $path) {
                if (is_string($path) && is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function thumbnailCommand(
        string $inputPath,
        string $outputPath,
    ): array {
        return [
            $this->binary(),
            '-nostdin',
            '-hide_banner',
            '-loglevel',
            'error',
            '-y',
            '-i',
            $inputPath,
            '-frames:v',
            '1',
            '-vf',
            "scale=w='min(640,iw)':h=-2",
            '-an',
            '-map_metadata',
            '-1',
            '-map_chapters',
            '-1',
            '-threads',
            '1',
            '-c:v',
            'libwebp',
            '-quality',
            '80',
            '-f',
            'webp',
            $outputPath,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function previewCommand(
        string $inputPath,
        string $outputPath,
    ): array {
        return [
            $this->binary(),
            '-nostdin',
            '-hide_banner',
            '-loglevel',
            'error',
            '-y',
            '-i',
            $inputPath,
            '-t',
            (string) $this->previewSeconds(),
            '-vf',
            "scale=w='min(720,iw)':h=-2,pad=ceil(iw/2)*2:ceil(ih/2)*2,fps=15",
            '-an',
            '-map_metadata',
            '-1',
            '-map_chapters',
            '-1',
            '-threads',
            '1',
            '-c:v',
            'libx264',
            '-preset',
            'veryfast',
            '-crf',
            '28',
            '-pix_fmt',
            'yuv420p',
            '-movflags',
            '+faststart',
            '-f',
            'mp4',
            $outputPath,
        ];
    }

    /**
     * @return array<string, int|string>
     */
    private function persistArtifact(
        MediaBlob $blob,
        string $localPath,
        string $profile,
        string $mimeType,
        string $extension,
    ): array {
        $byteSize = filesize($localPath);
        $sha256 = hash_file('sha256', $localPath);

        if (
            is_int($byteSize) === false
            || $byteSize <= 0
            || is_string($sha256) === false
            || strlen($sha256) !== 64
        ) {
            throw MediaProcessingException::derivativeInvalidOutput();
        }

        $storageKey = $this->storageKey(
            $blob,
            $profile,
            $extension,
        );
        $output = fopen($localPath, 'rb');

        if ($output === false) {
            throw MediaProcessingException::derivativeInvalidOutput();
        }

        try {
            if (
                Storage::disk($blob->storage_disk)
                    ->put($storageKey, $output) === false
            ) {
                throw MediaProcessingException::derivativeWriteFailed();
            }
        } finally {
            fclose($output);
        }

        return [
            'profile' => $profile,
            'storage_disk' => (string) $blob->storage_disk,
            'storage_key' => $storageKey,
            'mime_type' => $mimeType,
            'byte_size' => $byteSize,
            'sha256' => $sha256,
        ];
    }

    private function storageKey(
        MediaBlob $blob,
        string $profile,
        string $extension,
    ): string {
        $organizationId = (string) $blob->getAttribute('organization_id');
        $sha256 = (string) $blob->sha256;

        if (
            $organizationId === ''
            || strlen($sha256) !== 64
            || $profile === ''
            || $extension === ''
        ) {
            throw MediaProcessingException::derivativeFailed();
        }

        return sprintf(
            'organizations/%s/derivatives/%s/%s/%s.%s',
            $organizationId,
            substr($sha256, 0, 2),
            $sha256,
            $profile,
            $extension,
        );
    }

    private function temporaryPath(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);

        if ($path === false) {
            throw MediaProcessingException::derivativeFailed();
        }

        return $path;
    }

    private function binary(): string
    {
        $binary = (string) config(
            'grindflow.media.ffmpeg.binary',
            'ffmpeg',
        );

        if ($binary === '') {
            throw MediaProcessingException::derivativeFailed();
        }

        return $binary;
    }

    private function timeoutSeconds(): int
    {
        return max(10, min(
            (int) config(
                'grindflow.media.ffmpeg.timeout_seconds',
                60,
            ),
            300,
        ));
    }

    private function previewSeconds(): int
    {
        return max(3, min(
            (int) config(
                'grindflow.media.ffmpeg.preview_seconds',
                8,
            ),
            15,
        ));
    }
}
