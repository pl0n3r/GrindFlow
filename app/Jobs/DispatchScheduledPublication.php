<?php

namespace App\Jobs;

use App\Contracts\OrganizationAwareJob;
use App\Models\PublicationDelivery;
use App\Models\User;
use App\Queue\Middleware\UseOrganizationContext;
use App\Services\Distribution\PublicationDeliveryManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchScheduledPublication implements OrganizationAwareJob, ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $deliveryId,
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
        return $this->organization.':'.$this->deliveryId;
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
        return 'publication-delivery:'.$this->deliveryId;
    }

    public function handle(PublicationDeliveryManager $manager): void
    {
        $actor = User::query()->findOrFail($this->actor);
        $delivery = PublicationDelivery::query()
            ->findOrFail($this->deliveryId);

        $manager->dispatch($delivery, $actor);
    }
}
