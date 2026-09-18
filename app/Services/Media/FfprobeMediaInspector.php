<?php

namespace App\Services\Media;

use App\Models\MediaBlob;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use JsonException;

class FfprobeMediaInspector
{
    /**
     * @return array<string, mixed>
     */
    public function inspect(MediaBlob $blob): array
    {
        $source = Storage::disk($blob->storage_disk)
            ->readStream($blob->storage_key);

        if ($source === null) {
            throw MediaProcessingException::objectMissing();
        }

        $temporary = tmpfile();

        if ($temporary === false) {
            fclose($source);

            throw MediaProcessingException::probeFailed();
        }

        try {
            if (stream_copy_to_stream($source, $temporary) === false) {
                throw MediaProcessingException::probeFailed();
            }

            $metadata = stream_get_meta_data($temporary);
            $path = $metadata['uri'] ?? null;

            if (is_string($path) === false || $path === '') {
                throw MediaProcessingException::probeFailed();
            }

            try {
                $result = Process::timeout($this->timeoutSeconds())->run([
                    $this->binary(),
                    '-v',
                    'error',
                    '-show_streams',
                    '-show_format',
                    '-of',
                    'json',
                    $path,
                ]);
            } catch (ProcessTimedOutException) {
                throw MediaProcessingException::probeTimedOut();
            }

            if ($result->successful() === false) {
                throw MediaProcessingException::probeFailed();
            }

            return $this->normalize($result->output());
        } finally {
            fclose($source);
            fclose($temporary);
        }
    }

    public function enabled(): bool
    {
        return (bool) config(
            'grindflow.media.ffprobe.enabled',
            false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(string $output): array
    {
        try {
            $payload = json_decode(
                $output,
                true,
                32,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            throw MediaProcessingException::probeInvalidOutput();
        }

        if (is_array($payload) === false) {
            throw MediaProcessingException::probeInvalidOutput();
        }

        $streams = is_array($payload['streams'] ?? null)
            ? $payload['streams']
            : [];
        $format = is_array($payload['format'] ?? null)
            ? $payload['format']
            : [];

        $video = $this->firstStream($streams, 'video');
        $audio = $this->firstStream($streams, 'audio');

        return [
            'duration_seconds' => $this->decimal($format['duration'] ?? null),
            'format_name' => $this->text($format['format_name'] ?? null, 191),
            'stream_count' => count($streams),
            'video' => $video === null ? null : [
                'codec' => $this->text($video['codec_name'] ?? null, 64),
                'width' => $this->positiveInt($video['width'] ?? null),
                'height' => $this->positiveInt($video['height'] ?? null),
            ],
            'audio' => $audio === null ? null : [
                'codec' => $this->text($audio['codec_name'] ?? null, 64),
                'sample_rate' => $this->positiveInt(
                    $audio['sample_rate'] ?? null,
                ),
                'channels' => $this->positiveInt($audio['channels'] ?? null),
            ],
        ];
    }

    /**
     * @param  array<int|string, mixed>  $streams
     * @return array<string, mixed>|null
     */
    private function firstStream(array $streams, string $type): ?array
    {
        foreach ($streams as $stream) {
            if (
                is_array($stream)
                && ($stream['codec_type'] ?? null) === $type
            ) {
                return $stream;
            }
        }

        return null;
    }

    private function decimal(mixed $value): ?float
    {
        if (is_numeric($value) === false) {
            return null;
        }

        $decimal = (float) $value;

        if (
            is_finite($decimal) === false
            || $decimal < 0
            || $decimal > 604_800
        ) {
            return null;
        }

        return round($decimal, 3);
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_numeric($value) === false) {
            return null;
        }

        $number = (int) $value;

        return $number > 0 ? $number : null;
    }

    private function text(mixed $value, int $maxLength): ?string
    {
        if (is_string($value) === false || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $maxLength);
    }

    private function binary(): string
    {
        $binary = (string) config(
            'grindflow.media.ffprobe.binary',
            'ffprobe',
        );

        if ($binary === '') {
            throw MediaProcessingException::probeFailed();
        }

        return $binary;
    }

    private function timeoutSeconds(): int
    {
        return max(5, min(
            (int) config(
                'grindflow.media.ffprobe.timeout_seconds',
                30,
            ),
            120,
        ));
    }
}
