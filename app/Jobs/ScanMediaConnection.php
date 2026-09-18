<?php

namespace App\Jobs;

use App\Contracts\OrganizationAwareJob;
use App\Models\MediaConnection;
use App\Models\User;
use App\Queue\Middleware\UseOrganizationContext;
use App\Services\Media\Connections\MediaConnectionScanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ScanMediaConnection implements OrganizationAwareJob, ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly string $connectionId,
        private readonly string $organization,
        private readonly string $actor,
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [app(UseOrganizationContext::class)];
    }

    public function uniqueId(): string
    {
        return $this->organization.':'.$this->connectionId;
    }

    public function actorId(): string
    {
        return $this->actor;
    }

    public function organizationId(): string
    {
        return $this->organization;
    }

    public function idempotencyKey(): string
    {
        return 'media-connection-scan:'.$this->connectionId;
    }

    public function handle(MediaConnectionScanner $scanner): void
    {
        $connection = MediaConnection::query()->findOrFail($this->connectionId);
        $actor = User::query()->findOrFail($this->actor);

        $scanner->scan($connection, $actor);
    }
}
