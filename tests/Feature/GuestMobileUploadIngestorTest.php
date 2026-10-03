<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Membership;
use App\Models\MobileUploadGrantUse;
use App\Models\OperationalProfile;
use App\Models\Organization;
use App\Models\User;
use App\Models\VaultTriageItem;
use App\Services\Media\GuestMobileUploadIngestor;
use App\Services\Media\VaultOwnershipTriage;
use App\Support\Security\MobileUploadGrant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class GuestMobileUploadIngestorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config()->set('grindflow.media.disk', 'local');
    }

    public function test_guest_upload_enters_vault_with_explicit_profile_or_triage(): void
    {
        $actor = User::factory()->create();
        $organization = Organization::factory()->create();
        $this->membership($actor, $organization);

        $tenantContext = app(TenantContext::class);
        $profile = $tenantContext->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            fn (): OperationalProfile => OperationalProfile::query()->create([
                'name' => 'Explicit guest owner',
                'slug' => 'explicit-guest-owner',
            ]),
        );

        $service = $this->service($tenantContext);
        $explicitToken = $this->grant(
            (string) $organization->getKey(),
            'nonce-explicit',
        );

        $explicit = $service->ingest(
            $explicitToken,
            (string) $organization->getKey(),
            [UploadedFile::fake()->create('explicit.jpg', 4, 'image/jpeg')],
            (string) $profile->getKey(),
            1_100,
        );

        $this->assertCount(1, $explicit);
        $this->assertSame($profile->getKey(), $explicit[0]->profile_id);
        $this->assertNull($explicit[0]->ingested_by_user_id);
        $this->assertNull($tenantContext->organizationId());
        $this->assertNull($tenantContext->actorId());

        $ambiguousToken = $this->grant(
            (string) $organization->getKey(),
            'nonce-ambiguous',
        );

        $ambiguous = $service->ingest(
            $ambiguousToken,
            (string) $organization->getKey(),
            [UploadedFile::fake()->create('ambiguous.png', 4, 'image/png')],
            null,
            1_100,
        );

        $this->assertCount(1, $ambiguous);
        $this->assertNull($ambiguous[0]->profile_id);
        $this->assertNull($ambiguous[0]->ingested_by_user_id);

        $tenantContext->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            function () use ($ambiguous): void {
                $item = VaultTriageItem::query()
                    ->where('media_asset_id', $ambiguous[0]->getKey())
                    ->first();

                $this->assertInstanceOf(VaultTriageItem::class, $item);
                $this->assertSame(VaultTriageItem::STATUS_PENDING, $item->status);
                $this->assertNull($item->assigned_profile_id);
                $this->assertSame(2, MobileUploadGrantUse::query()->count());
            },
        );
    }

    public function test_invalid_media_and_ambiguous_owner_never_bypass_validation_or_triage(): void
    {
        $organization = Organization::factory()->create();
        $foreignOrganization = Organization::factory()->create();
        $foreignActor = User::factory()->create();
        $this->membership($foreignActor, $foreignOrganization);

        $tenantContext = app(TenantContext::class);
        $foreignProfile = $tenantContext->runWithinOrganization(
            $foreignActor,
            (string) $foreignOrganization->getKey(),
            fn (): OperationalProfile => OperationalProfile::query()->create([
                'name' => 'Foreign profile',
                'slug' => 'foreign-profile',
            ]),
        );

        $service = $this->service($tenantContext);

        try {
            $service->ingest(
                $this->grant((string) $organization->getKey(), 'nonce-invalid-mime'),
                (string) $organization->getKey(),
                [UploadedFile::fake()->create('payload.txt', 1, 'text/plain')],
                null,
                1_100,
            );
            $this->fail('Invalid MIME must fail closed.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        try {
            $service->ingest(
                $this->grant(
                    (string) $organization->getKey(),
                    'nonce-too-large',
                    maxBytes: 1_024,
                ),
                (string) $organization->getKey(),
                [UploadedFile::fake()->create('too-large.jpg', 4, 'image/jpeg')],
                null,
                1_100,
            );
            $this->fail('Byte cap must fail closed.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        try {
            $service->ingest(
                $this->grant((string) $organization->getKey(), 'nonce-cross-profile'),
                (string) $organization->getKey(),
                [UploadedFile::fake()->create('cross.jpg', 1, 'image/jpeg')],
                (string) $foreignProfile->getKey(),
                1_100,
            );
            $this->fail('Cross-tenant profile must fail closed.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $replayToken = $this->grant(
            (string) $organization->getKey(),
            'nonce-replay',
        );
        $service->ingest(
            $replayToken,
            (string) $organization->getKey(),
            [UploadedFile::fake()->create('first.jpg', 1, 'image/jpeg')],
            null,
            1_100,
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('already been consumed');

        $service->ingest(
            $replayToken,
            (string) $organization->getKey(),
            [UploadedFile::fake()->create('replay.jpg', 1, 'image/jpeg')],
            null,
            1_100,
        );
    }

    public function test_guest_context_is_actorless_restored_and_rejects_unknown_tenant(): void
    {
        $actor = User::factory()->create();
        $organization = Organization::factory()->create();
        $this->membership($actor, $organization);
        $tenantContext = app(TenantContext::class);

        $tenantContext->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            function () use ($actor, $organization, $tenantContext): void {
                $this->assertSame((string) $actor->getKey(), $tenantContext->actorId());

                $tenantContext->runWithinGuestOrganization(
                    (string) $organization->getKey(),
                    function () use ($organization, $tenantContext): void {
                        $this->assertNull($tenantContext->actorId());
                        $this->assertSame(
                            (string) $organization->getKey(),
                            $tenantContext->organizationId(),
                        );
                    },
                );

                $this->assertSame((string) $actor->getKey(), $tenantContext->actorId());
                $this->assertSame(
                    (string) $organization->getKey(),
                    $tenantContext->organizationId(),
                );
            },
        );

        $this->assertNull($tenantContext->actorId());
        $this->assertNull($tenantContext->organizationId());

        $this->expectException(AuthorizationException::class);
        $tenantContext->runWithinGuestOrganization(
            '00000000-0000-4000-8000-000000000000',
            fn (): null => null,
        );
    }

    private function service(TenantContext $tenantContext): GuestMobileUploadIngestor
    {
        return new GuestMobileUploadIngestor(
            new MobileUploadGrant(str_repeat('g', 32)),
            $tenantContext,
            app(VaultOwnershipTriage::class),
        );
    }

    private function grant(
        string $organizationId,
        string $nonce,
        int $maxFiles = 5,
        int $maxBytes = 10_485_760,
    ): string {
        return (new MobileUploadGrant(str_repeat('g', 32)))->issue(
            $organizationId,
            1_000,
            1_600,
            $maxFiles,
            $maxBytes,
            $nonce,
        );
    }

    private function membership(User $user, Organization $organization): Membership
    {
        return Membership::query()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'role' => UserRole::Studio,
        ]);
    }
}
