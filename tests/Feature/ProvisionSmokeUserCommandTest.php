<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProvisionSmokeUserCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config([
            'grindflow.phase' => 'construccion',
            'grindflow.smoke_user.email' => 'e2e-admin@grindflow.test',
            'grindflow.smoke_user.password' => 'synthetic-secret-with-safe-length',
            'grindflow.smoke_user.name' => 'GrindFlow Production Smoke',
        ]);
    }

    public function test_it_fails_closed_without_password_and_writes_nothing(): void
    {
        config(['grindflow.smoke_user.password' => '']);

        $this->artisan('grindflow:provision-smoke-user')
            ->expectsOutputToContain('SMOKE_USER_PASSWORD is required')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
        self::assertSame([], Storage::disk('local')->allFiles('operations/smoke-user-backups'));
    }

    public function test_it_rejects_non_synthetic_email_before_any_write(): void
    {
        config(['grindflow.smoke_user.email' => 'someone@example.com']);

        $this->artisan('grindflow:provision-smoke-user')
            ->expectsOutputToContain('reserved grindflow.test synthetic domain')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_creates_only_the_required_synthetic_platform_admin_with_private_backup(): void
    {
        $this->artisan('grindflow:provision-smoke-user')
            ->expectsOutputToContain('Synthetic smoke identity reconciled')
            ->assertSuccessful();

        $user = User::query()->sole();

        self::assertSame('e2e-admin@grindflow.test', $user->email);
        self::assertSame(UserRole::Admin, $user->platform_role);
        self::assertNotNull($user->email_verified_at);
        self::assertTrue(Hash::check('synthetic-secret-with-safe-length', $user->getAuthPassword()));
        self::assertSame(0, Membership::query()->where('user_id', $user->getKey())->count());

        $backups = Storage::disk('local')->allFiles('operations/smoke-user-backups');
        self::assertCount(1, $backups);
        $snapshot = json_decode(
            Crypt::decryptString((string) Storage::disk('local')->get($backups[0])),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertFalse($snapshot['exists']);
        self::assertNull($snapshot['user']);
    }

    public function test_it_is_idempotent_and_does_not_rehash_or_duplicate_backup(): void
    {
        $this->artisan('grindflow:provision-smoke-user')->assertSuccessful();

        $user = User::query()->sole();
        $originalHash = $user->getAuthPassword();
        $originalUpdatedAt = $user->updated_at?->format('Y-m-d H:i:s.u');
        $backupsBefore = Storage::disk('local')->allFiles('operations/smoke-user-backups');

        $this->artisan('grindflow:provision-smoke-user')
            ->expectsOutputToContain('already reconciled')
            ->assertSuccessful();

        $user->refresh();
        self::assertSame($originalHash, $user->getAuthPassword());
        self::assertSame($originalUpdatedAt, $user->updated_at?->format('Y-m-d H:i:s.u'));
        self::assertSame(
            $backupsBefore,
            Storage::disk('local')->allFiles('operations/smoke-user-backups'),
        );
    }

    public function test_it_backs_up_then_reconciles_an_existing_synthetic_account_without_memberships(): void
    {
        $user = User::factory()->create([
            'name' => 'Old synthetic identity',
            'email' => 'e2e-admin@grindflow.test',
            'password' => 'old-secret',
            'email_verified_at' => null,
            'platform_role' => UserRole::Model,
        ]);

        $this->artisan('grindflow:provision-smoke-user')->assertSuccessful();

        $user->refresh();
        self::assertSame(UserRole::Admin, $user->platform_role);
        self::assertNotNull($user->email_verified_at);
        self::assertTrue(Hash::check('synthetic-secret-with-safe-length', $user->getAuthPassword()));
        self::assertSame(0, Membership::query()->where('user_id', $user->getKey())->count());

        $backups = Storage::disk('local')->allFiles('operations/smoke-user-backups');
        self::assertCount(1, $backups);
        $snapshot = json_decode(
            Crypt::decryptString((string) Storage::disk('local')->get($backups[0])),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertTrue($snapshot['exists']);
        self::assertSame('model', $snapshot['user']['platform_role']);
        self::assertTrue(Hash::check('old-secret', $snapshot['user']['password']));
    }

    public function test_command_output_never_contains_identity_or_secret(): void
    {
        $result = $this->artisan('grindflow:provision-smoke-user');
        $result->assertSuccessful();

        $output = $result->run();

        self::assertStringNotContainsString('e2e-admin@grindflow.test', $output);
        self::assertStringNotContainsString('synthetic-secret-with-safe-length', $output);
    }
}
