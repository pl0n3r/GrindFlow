<?php

namespace App\Services\Media;

use App\Models\MediaBlob;
use Illuminate\Support\Facades\Storage;

class FfmpegMediaDerivativeGenerator
{
    public const PROFILE = 'thumbnail_v1';

    public const MIME_TYPE = 'image/webp';

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

    /**
     * @return array<string, array<string, int|string>>
     */
    public function generate(MediaBlob $blob): array
    {
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

        $outputPath = tempnam(sys_get_temp_dir(), 'grindflow-thumb-');

        if ($outputPath === false) {
            fclose($source);
            fclose($input);

            throw MediaProcessingException::derivativeFailed();
        }

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
                $this->command($inputPath, $outputPath),
                $this->timeoutSeconds(),
            );

            $byteSize = filesize($outputPath);
            $sha256 = hash_file('sha256', $outputPath);

            if (
                is_int($byteSize) === false
                || $byteSize <= 0
                || is_string($sha256) === false
                || strlen($sha256) !== 64
            ) {
                throw MediaProcessingException::derivativeInvalidOutput();
            }

            $storageKey = $this->storageKey($blob);
            $output = fopen($outputPath, 'rb');

            if ($output === false) {
                throw MediaProcessingException::derivativeInvalidOutput();
            }

            try {
                if ($disk->put($storageKey, $output) === false) {
                    throw MediaProcessingException::derivativeWriteFailed();
                }
            } finally {
                fclose($output);
            }

            return [
                'thumbnail' => [
                    'profile' => self::PROFILE,
                    'storage_disk' => (string) $blob->storage_disk,
                    'storage_key' => $storageKey,
                    'mime_type' => self::MIME_TYPE,
                    'byte_size' => $byteSize,
                    'sha256' => $sha256,
                ],
            ];
        } finally {
            fclose($source);
            fclose($input);

            if (is_file($outputPath)) {
                unlink($outputPath);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function command(string $inputPath, string $outputPath): array
    {
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

    private function storageKey(MediaBlob $blob): string
    {
        $organizationId = (string) $blob->getAttribute('organization_id');
        $sha256 = (string) $blob->sha256;

        if ($organizationId === '' || strlen($sha256) !== 64) {
            throw MediaProcessingException::derivativeFailed();
        }

        return sprintf(
            'organizations/%s/derivatives/%s/%s/%s.webp',
            $organizationId,
            substr($sha256, 0, 2),
            $sha256,
            self::PROFILE,
        );
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
}
