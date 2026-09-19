<?php

namespace App\Http\Controllers\Distribution;

use App\Http\Controllers\Controller;
use App\Http\Requests\Distribution\StoreDestinationRequest;
use App\Http\Requests\Distribution\UpdateDestinationRequest;
use App\Models\Organization;
use App\Models\PublicationDelivery;
use App\Models\PublishingDestination;
use App\Models\User;
use App\Services\Distribution\PublicationDeliveryManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DistributionController extends Controller
{
    public function index(Request $request): View
    {
        $organization = $this->organization($request);
        $ready = $this->ready();
        $destinations = collect();
        $deliveries = collect();
        $counts = collect();

        $filters = $request->validate([
            'status' => ['nullable', Rule::in([
                PublicationDelivery::STATUS_QUEUED,
                PublicationDelivery::STATUS_PROCESSING,
                PublicationDelivery::STATUS_RETRY_SCHEDULED,
                PublicationDelivery::STATUS_PUBLISHED,
                PublicationDelivery::STATUS_AUTHENTICATION_FAILED,
                PublicationDelivery::STATUS_FAILED,
            ])],
            'destination_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        if ($ready) {
            $destinations = PublishingDestination::query()->orderBy('name')->get();

            $query = PublicationDelivery::query()
                ->with(['scheduledPublication.mediaAsset', 'scheduledPublication.destination'])
                ->latest();

            $query->when(
                $filters['status'] ?? null,
                fn ($query, $status) => $query->where('status', $status),
            );
            $query->when(
                $filters['destination_id'] ?? null,
                function ($query, $destinationId): void {
                    $query->whereHas(
                        'scheduledPublication',
                        fn ($publication) => $publication->where(
                            'publishing_destination_id',
                            $destinationId,
                        ),
                    );
                },
            );
            $query->when(
                $filters['from'] ?? null,
                fn ($query, $from) => $query->whereDate('created_at', '>=', $from),
            );
            $query->when(
                $filters['to'] ?? null,
                fn ($query, $to) => $query->whereDate('created_at', '<=', $to),
            );

            $deliveries = $query->limit(100)->get();
            $counts = PublicationDelivery::query()
                ->selectRaw('status, COUNT(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status');
        }

        /** @var User $user */
        $user = $request->user();

        return view('distribution.index', compact(
            'organization', 'ready', 'destinations', 'deliveries', 'counts', 'filters',
        ) + ['canManageDestinations' => $user->canManageOrganization($organization)]);
    }

    public function storeDestination(StoreDestinationRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        PublishingDestination::query()->create([
            'name' => trim((string) $validated['name']),
            'provider' => 'sandbox',
            'status' => PublishingDestination::STATUS_ACTIVE,
            'metadata' => ['mode' => 'sandbox'],
        ]);

        return $this->back($request, 'Destino sandbox creado. No publica en servicios externos.');
    }

    public function updateDestination(
        UpdateDestinationRequest $request,
        string $destinationId,
    ): RedirectResponse {
        $destination = PublishingDestination::query()->findOrFail($destinationId);
        $destination->forceFill($request->safe()->only(['name', 'status']))->save();

        return $this->back($request, 'Destino actualizado.');
    }

    public function retry(
        Request $request,
        string $deliveryId,
        PublicationDeliveryManager $manager,
    ): RedirectResponse {
        $organization = $this->organization($request);

        /** @var User $user */
        $user = $request->user();

        abort_unless($user->canManageOrganization($organization), 403);

        $delivery = PublicationDelivery::query()->findOrFail($deliveryId);
        abort_unless(in_array($delivery->status, [
            PublicationDelivery::STATUS_FAILED,
            PublicationDelivery::STATUS_RETRY_SCHEDULED,
        ], true), 409, 'Only failed or scheduled retries can be queued manually.');

        $delivery->forceFill([
            'status' => PublicationDelivery::STATUS_RETRY_SCHEDULED,
            'attempts' => 0,
            'next_attempt_at' => now('UTC'),
            'claimed_until' => null,
        ])->save();

        $manager->queue($delivery->scheduledPublication()->firstOrFail(), $user);

        return $this->back($request, 'Reintento encolado con la misma clave de idempotencia.');
    }

    private function ready(): bool
    {
        return Schema::hasTable('publication_deliveries')
            && Schema::hasTable('scheduled_publications')
            && Schema::hasTable('publishing_destinations');
    }

    private function organization(Request $request): Organization
    {
        $organization = $request->attributes->get('tenantOrganization');

        abort_unless($organization instanceof Organization, 404);

        return $organization;
    }

    private function back(Request $request, string $status): RedirectResponse
    {
        return redirect()->route('organizations.distribution.index', [
            'organizationId' => $this->organization($request)->getKey(),
        ])->with('status', $status);
    }
}
