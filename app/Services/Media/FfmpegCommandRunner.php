<?php

namespace App\Services\Media;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

class FfmpegCommandRunner
{
    /**
     * @param  array<int, string>  $command
     */
    public function run(array $command, int $timeoutSeconds): void
    {
        try {
            $result = Process::timeout($timeoutSeconds)->run($command);
        } catch (ProcessTimedOutException) {
            throw MediaProcessingException::derivativeTimedOut();
        }

        if ($result->successful() === false) {
            throw MediaProcessingException::derivativeFailed();
        }
    }
}
