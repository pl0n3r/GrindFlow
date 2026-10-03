<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MediaAsset;
use App\Models\MediaBlob;
use App\Models\Membership;
use App\Models\OperationalProfile;
use App\Models\Organization;
use App\Models\User;
use App\Models\VaultTriageItem;
use App\Services\Media\VaultOwnershipTriage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class VaultTriageTest extends TestCase
{
    use RefreshDatabase;

    public function test_ambiguous_asset_enters_triage_without_guessing_owner(): void
    {
        $actor = User::factory()->create();
        $organization = Organization::factory()->create();
        $this->membership($actor, $organization, UserRole::Studio);

        app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            function (): void {
                $asset = $this->asset('ambiguous.jpg', 'a');
                OperationalProfile::query()->create([
                    'name' => 'Candidate profile',
                    'slug' => 'candidate-profile',
                ]);

                $item = app(VaultOwnershipTriage::class)->queueAmbiguous($asset);

                $this->assertSame(VaultTriageItem::STATUS_PENDING, $item->status);
                $this->assertNull($item->assigned_profile_id);
                $this->assertNull($item->assigned_by_user_id);
                $this->assertNull($item->assigned_at);
                $this->assertNull($asset->refresh()->profile_id);
                $this->assertSame(1, VaultTriageItem::query()->count());
            },
        );
    }

    public function test_authorized_operator_assigns_profile_with_auditable_transition(): void
    {
        $actor = User::factory()->create();
        $organization = Organization::factory()->create();
        $this->membership($actor, $organization, UserRole::Studio);

        app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            function () use ($actor): void {
                $asset = $this->asset('assignable.jpg', 'b');
                $profile = OperationalProfile::query()->create([
                    'name' => 'Explicit owner',
                    'slug' => 'explicit-owner',
                ]);
                $item = app(VaultOwnershipTriage::class)->queueAmbiguous($asset);

                $assigned = app(VaultOwnershipTriage::class)->assign($item, $profile, $actor);

                $this->assertSame(VaultTriageItem::STATUS_ASSIGNED, $assigned->status);
                $this->assertSame($profile->getKey(), $assigned->assigned_profile_id);
                $this->assertSame($actor->getKey(), $assigned->assigned_by_user_id);
                $this->assertNotNull($assigned->assigned_at);
                $this->assertSame($profile->getKey(), $asset->refresh()->profile_id);
            },
        );
    }

    public function test_cross_tenant_unauthorized_or_reassignment_is_rejected(): void
    {
        $actor = User::factory()->create();
        $viewer = User::factory()->create();
        $foreignActor = User::factory()->create();
        $organization = Organization::factory()->create();
        $foreignOrganization = Organization::factory()->create();

        $this->membership($actor, $organization, UserRole::Studio);
        $this->membership($viewer, $organization, UserRole::Model);
        $this->membership($foreignActor, $foreignOrganization, UserRole::Studio);

        [$item, $profile, $alternate] = app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            function (): array {
                return [
                    app(VaultOwnershipTriage::class)->queueAmbiguous($this->asset('guarded.jpg', 'c')),
                    OperationalProfile::query()->create(['name' => 'Owner A', 'slug' => 'owner-a']),
                    OperationalProfile::query()->create(['name' => 'Owner B', 'slug' => 'owner-b']),
                ];
            },
        );

        $foreignProfile = app(TenantContext::class)->runWithinOrganization(
            $foreignActor,
            (string) $foreignOrganization->getKey(),
            fn (): OperationalProfile => OperationalProfile::query()->create([
                'name' => 'Foreign owner',
                'slug' => 'foreign-owner',
            ]),
        );

        $unauthorized = false;
        try {
            app(TenantContext::class)->runWithinOrganization(
                $viewer,
                (string) $organization->getKey(),
                fn (): VaultTriageItem => app(VaultOwnershipTriage::class)->assign($item, $profile, $viewer),
            );
        } catch (AuthorizationException) {
            $unauthorized = true;
        }
        $this->assertTrue($unauthorized);

        $crossTenant = false;
        try {
            app(TenantContext::class)->runWithinOrganization(
                $actor,
                (string) $organization->getKey(),
                fn (): VaultTriageItem => app(VaultOwnershipTriage::class)->assign($item, $foreignProfile, $actor),
            );
        } catch (AuthorizationException) {
            $crossTenant = true;
        }
        $this->assertTrue($crossTenant);

        app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            function () use ($item, $profile, $alternate, $actor): void {
                app(VaultOwnershipTriage::class)->assign($item, $profile, $actor);

                $reassignment = false;
                try {
                    app(VaultOwnershipTriage::class)->assign($item, $alternate, $actor);
                } catch (LogicException) {
                    $reassignment = true;
                }

                $this->assertTrue($reassignment);
            },
        );
    }

    private function asset(string $filename, string $shaCharacter): MediaAsset
    {
        $blob = MediaBlob::query()->create([
            'storage_disk' => 'local',
            'storage_key' => 'triage/'.$filename,
            'sha256' => str_repeat($shaCharacter, 64),
            'byte_size' => 42,
            'mime_type' => 'image/jpeg',
        ]);

        return MediaAsset::query()->create([
            'media_blob_id' => $blob->getKey(),
            'original_filename' => $filename,
            'source_type' => 'manual_upload',
            'status' => MediaAsset::STATUS_READY,
        ]);
    }

    private function membership(User $user, Organization $organization, UserRole $role): Membership
    {
        return Membership::query()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
        ]);
    }
}
