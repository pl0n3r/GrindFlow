<?php

namespace Tests\Feature;

use App\Support\Operations\MediaToolReadiness;
use Tests\TestCase;

class MediaToolReadinessTest extends TestCase
{
    public function test_disabled_tools_never_claim_executable_readiness(): void
    {
        config([
            'grindflow.media.ffmpeg.enabled' => false,
            'grindflow.media.ffmpeg.binary' => PHP_BINARY,
            'grindflow.media.ffprobe.enabled' => false,
            'grindflow.media.ffprobe.binary' => PHP_BINARY,
        ]);

        $this->assertSame([
            'ffmpeg' => 'disabled',
            'ffprobe' => 'disabled',
        ], app(MediaToolReadiness::class)->status());
    }

    public function test_enabled_tools_report_executable_presence_without_running_it(): void
    {
        config([
            'grindflow.media.ffmpeg.enabled' => true,
            'grindflow.media.ffmpeg.binary' => PHP_BINARY,
            'grindflow.media.ffprobe.enabled' => true,
            'grindflow.media.ffprobe.binary' => 'grindflow-missing-probe-9a81e2',
        ]);

        $this->assertSame([
            'ffmpeg' => 'binary-found',
            'ffprobe' => 'binary-missing',
        ], app(MediaToolReadiness::class)->status());
    }

    public function test_blank_or_invalid_binary_cannot_appear_available(): void
    {
        config([
            'grindflow.media.ffmpeg.enabled' => true,
            'grindflow.media.ffmpeg.binary' => '',
            'grindflow.media.ffprobe.enabled' => true,
            'grindflow.media.ffprobe.binary' => null,
        ]);

        $this->assertSame([
            'ffmpeg' => 'binary-missing',
            'ffprobe' => 'binary-missing',
        ], app(MediaToolReadiness::class)->status());
    }
}
