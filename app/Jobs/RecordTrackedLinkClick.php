<?php

namespace App\Jobs;

use App\Services\Traffic\TrafficAttributionRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecordTrackedLinkClick implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public readonly string $organizationId,
        public readonly string $trackedLinkId,
        public readonly string $visitorHash,
        public readonly string $clickedAtUtc,
    ) {}

    public function handle(
        TrafficAttributionRecorder $recorder,
    ): void {
        $recorder->record(
            $this->organizationId,
            $this->trackedLinkId,
            $this->visitorHash,
            CarbonImmutable::parse($this->clickedAtUtc, 'UTC'),
        );
    }
}
