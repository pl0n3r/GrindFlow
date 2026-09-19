<?php

namespace Tests\Feature;

use App\Services\Media\FfmpegCommandRunner;
use App\Services\Media\MediaProcessingException;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

class FfmpegCommandRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Process::preventStrayProcesses();
    }

    public function test_runner_uses_array_command_and_bounded_timeout(): void
    {
        Process::fake([
            '*' => Process::result(),
        ]);

        app(FfmpegCommandRunner::class)->run(
            ['ffmpeg', '-version'],
            45,
        );

        Process::assertRan(function (
            PendingProcess $process,
            ProcessResult $result,
        ): bool {
            return is_array($process->command)
                && $process->command === ['ffmpeg', '-version']
                && $process->timeout === 45
                && $result->successful();
        });
    }

    public function test_runner_maps_failure_without_exposing_stderr(): void
    {
        Process::fake([
            '*' => Process::result(
                errorOutput: 'sensitive-ffmpeg-stderr',
                exitCode: 1,
            ),
        ]);

        try {
            app(FfmpegCommandRunner::class)->run(
                ['ffmpeg', '-version'],
                45,
            );

            $this->fail('Expected ffmpeg failure to be mapped safely.');
        } catch (MediaProcessingException $exception) {
            $this->assertSame(
                'processing_derivative_failed',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString(
                'sensitive-ffmpeg-stderr',
                $exception->getMessage(),
            );
        }
    }

    public function test_runner_maps_timeout_to_safe_error(): void
    {
        $symfonyProcess = new SymfonyProcess(['ffmpeg']);
        $symfonyProcess->setTimeout(45);

        Process::fake(fn () => new ProcessTimedOutException(
            new SymfonyProcessTimedOutException(
                $symfonyProcess,
                SymfonyProcessTimedOutException::TYPE_GENERAL,
            ),
            Process::result(exitCode: 1),
        ));

        try {
            app(FfmpegCommandRunner::class)->run(
                ['ffmpeg', '-version'],
                45,
            );

            $this->fail('Expected ffmpeg timeout to be mapped safely.');
        } catch (MediaProcessingException $exception) {
            $this->assertSame(
                'processing_derivative_timed_out',
                $exception->getMessage(),
            );
        }
    }
}
