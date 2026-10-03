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
use App\Support\Tenancy\TenantContext;
use App\Support\Vault\VaultTriageQueue;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class VaultTriageTest extends TestCase
{
    use RefreshDatabase;

    public function test_ambiguous_asset_enters_triage_without_guessing_owner(): void
    {
        $operator = User::factory()->create();
        $organization = Organization::factory()->create();
        $this->membership($operator, $organization, UserRole::Studio);

        app(TenantContext::class)->runWithinOrganization(
            $operator,
            (string) $organization->getKey(),
            function (): void {
                $asset = $this->asset('ambiguous.jpg', 'a');
                $candidate = OperationalProfile::query()->create([
                    'name' => 'Candidate profile',
                    'slug' => 'candidate-profile',
                ]);

                $item = app(VaultTriageQueue::class)->enqueueAmbiguous($asset);

                $this->assertSame(VaultTriageItem::STATUS_PENDING, $item->status);
                $this->assertNull($item->operational_profile_id);
                $this->assertNull($item->assigned_by_user_id);
                $this->assertNull($item->assigned_at);
                $this->assertNotSame($candidate->getKey(), $item->operational_profile_id);
                $this->assertSame(1, VaultTriageItem::query()->count());
            },
        );
    }

    public function test_authorized_operator_assigns_profile_with_auditable_transition(): void
    {
        $operator = User::factory()->create();
        $organization = Organization::factory()->create();
        $this->membership($operator, $organization, UserRole::Studio);

        app(TenantContext::class)->runWithinOrganization(
            $operator,
            (string) $organization->getKey(),
            function () use ($operator): void {
                $asset = $this->asset('assignable.jpg', 'b');
                $profile = OperationalProfile::query()->create([
                    'name' => 'Explicit owner',
                    'slug' => 'explicit-owner',
                ]);
                $item = app(VaultTriageQueue::class)->enqueueAmbiguous($asset);

                $assigned = app(VaultTriageQueue::class)->assignProfile(
                    $item,
                    $profile,
                    $operator,
                );

                $this->assertSame(VaultTriageItem::STATUS_ASSIGNED, $assigned->status);
                $this->assertSame($profile->getKey(), $assigned->operational_profile_id);
                $this->assertSame($operator->getKey(), $assigned->assigned_by_user_id);
                $this->assertNotNull($assigned->assigned_at);
            },
        );
    }

    public function test_cross_tenant_unauthorized_or_reassignment_is_rejected(): void
    {
        $operator = User::factory()->create();
        $viewer = User::factory()->create();
        $foreignOperator = User::factory()->create();
        $organization = Organization::factory()->create();
        $foreignOrganization = Organization::factory()->create();

        $this->membership($operator, $organization, UserRole::Studio);
        $this->membership($viewer, $organization, UserRole::Model);
        $this->membership($foreignOperator, $foreignOrganization, UserRole::Studio);

        [$item, $profile, $alternate] = app(TenantContext::class)->runWithinOrganization(
            $operator,
            (string) $organization->getKey(),
            function (): array {
                $item = app(VaultTriageQueue::class)->enqueueAmbiguous(
                    $this->asset('guarded.jpg', 'c'),
                );

                return [
                    $item,
                    OperationalProfile::query()->create([
                        'name' => 'Owner A',
                        'slug' => 'owner-a',
                    ]),
                    OperationalProfile::query()->create([
                        'name' => 'Owner B',
                        'slug' => 'owner-b',
                    ]),
                ];
            },
        );

        $foreignProfile = app(TenantContext::class)->runWithinOrganization(
            $foreignOperator,
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
                fn (): VaultTriageItem => app(VaultTriageQueue::class)->assignProfile(
                    $item,
                    $profile,
                    $viewer,
                ),
            );
        } catch (AuthorizationException) {
            $unauthorized = true;
        }
        $this->assertTrue($unauthorized);

        $crossTenant = false;
        try {
            app(TenantContext::class)->runWithinOrganization(
                $operator,
                (string) $organization->getKey(),
                fn (): VaultTriageItem => app(VaultTriageQueue::class)->assignProfile(
                    $item,
                    $foreignProfile,
                    $operator,
                ),
            );
        } catch (AuthorizationException) {
            $crossTenant = true;
        }
        $this->assertTrue($crossTenant);

        app(TenantContext::class)->runWithinOrganization(
            $operator,
            (string) $organization->getKey(),
            function () use ($item, $profile, $alternate, $operator): void {
                app(VaultTriageQueue::class)->assignProfile($item, $profile, $operator);

                $reassignment = false;
                try {
                    app(VaultTriageQueue::class)->assignProfile($item, $alternate, $operator);
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

    private function membership(
        User $user,
        Organization $organization,
        UserRole $role,
    ): Membership {
        return Membership::query()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
        ]);
    }
}
