<?php

namespace App\Support\Operations;

use Symfony\Component\Process\ExecutableFinder;
use Throwable;

class MediaToolReadiness
{
    public function __construct(
        private readonly ExecutableFinder $finder,
    ) {}

    /**
     * Reports configuration and executable presence only.
     * It does not execute commands or claim codec/encode compatibility.
     *
     * @return array{ffmpeg: string, ffprobe: string}
     */
    public function status(): array
    {
        return [
            'ffmpeg' => $this->toolStatus('ffmpeg'),
            'ffprobe' => $this->toolStatus('ffprobe'),
        ];
    }

    private function toolStatus(string $tool): string
    {
        if ((bool) config("grindflow.media.{$tool}.enabled", false) === false) {
            return 'disabled';
        }

        $binary = config("grindflow.media.{$tool}.binary", $tool);

        if (is_string($binary) === false || trim($binary) === '') {
            return 'binary-missing';
        }

        try {
            // Symfony's PATH lookup is for command names; configured absolute
            // paths require an explicit executable-file check instead.
            $found = str_contains($binary, '/') || str_contains($binary, '\\')
                ? is_file($binary) && is_executable($binary)
                : $this->finder->find($binary) !== null;

            return $found ? 'binary-found' : 'binary-missing';
        } catch (Throwable) {
            // Never echo an operator-controlled path or process error into the UI.
            return 'binary-missing';
        }
    }
}
