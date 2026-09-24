<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Support\Deployment\ProductionEnvironmentWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PDOException;
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
        self::assertSame('provision-password-missing', config('grindflow.smoke_provision_failure_code'));
        self::assertSame([], Storage::disk('local')->allFiles('operations/smoke-user-backups'));
    }

    public function test_it_rejects_non_synthetic_email_before_any_write(): void
    {
        config(['grindflow.smoke_user.email' => 'someone@example.com']);

        $this->artisan('grindflow:provision-smoke-user')
            ->expectsOutputToContain('reserved grindflow.test synthetic domain')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
        self::assertSame('provision-email-invalid', config('grindflow.smoke_provision_failure_code'));
    }

    public function test_it_classifies_lock_directory_and_open_failures_without_writing_data(): void
    {
        $previousStorage = $this->app->storagePath();
        $temporaryStorage = sys_get_temp_dir().'/grindflow-lock-test-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($temporaryStorage, 0700));

        $framework = $temporaryStorage.'/framework';
        $lock = $framework.'/grindflow-smoke-user.lock';

        try {
            $this->app->useStoragePath($temporaryStorage);
            self::assertNotFalse(file_put_contents($framework, 'not a directory'));

            $this->artisan('grindflow:provision-smoke-user')
                ->expectsOutputToContain('Synthetic smoke identity reconciliation failed safely.')
                ->assertFailed();
            self::assertSame('provision-lock-directory-failed', config('grindflow.smoke_provision_failure_code'));

            self::assertTrue(unlink($framework));
            self::assertTrue(mkdir($framework, 0700));
            self::assertTrue(mkdir($lock, 0700));

            $this->artisan('grindflow:provision-smoke-user')
                ->expectsOutputToContain('Synthetic smoke identity reconciliation failed safely.')
                ->assertFailed();
            self::assertSame('provision-lock-open-failed', config('grindflow.smoke_provision_failure_code'));
        } finally {
            $this->app->useStoragePath($previousStorage);

            if (is_file($framework)) {
                unlink($framework);
            }
            if (is_dir($lock)) {
                rmdir($lock);
            }
            if (is_dir($framework)) {
                rmdir($framework);
            }
            rmdir($temporaryStorage);
        }

        $this->assertDatabaseCount('users', 0);
        self::assertSame([], Storage::disk('local')->allFiles('operations/smoke-user-backups'));
    }

    public function test_it_classifies_a_pdo_failure_before_the_transaction_callback(): void
    {
        DB::shouldReceive('transaction')
            ->once()
            ->andThrow(new PDOException('Synthetic transaction startup failure.'));

        $this->artisan('grindflow:provision-smoke-user')
            ->expectsOutputToContain('Synthetic smoke identity reconciliation failed safely.')
            ->assertFailed();

        self::assertSame('provision-database-failed', config('grindflow.smoke_provision_failure_code'));
        self::assertSame([], Storage::disk('local')->allFiles('operations/smoke-user-backups'));
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

        config(['grindflow.smoke_provision_failure_code' => 'provision-membership-conflict']);
        $this->artisan('grindflow:provision-smoke-user')
            ->expectsOutputToContain('already reconciled')
            ->assertSuccessful();

        $user->refresh();
        self::assertNull(config('grindflow.smoke_provision_failure_code'));
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

    public function test_it_provisions_a_dedicated_identity_without_mutating_the_member_bound_legacy_account(): void
    {
        $legacy = User::factory()->create([
            'email' => 'e2e-admin@grindflow.test',
            'name' => 'Legacy synthetic member',
            'password' => 'legacy-secret',
            'platform_role' => UserRole::Model,
        ]);
        $organization = Organization::factory()->create();
        Membership::query()->create([
            'user_id' => $legacy->getKey(),
            'organization_id' => $organization->getKey(),
            'role' => UserRole::Model,
        ]);
        $legacyHash = $legacy->getAuthPassword();
        $legacyUpdatedAt = $legacy->updated_at?->format('Y-m-d H:i:s.u');
        config(['grindflow.smoke_user.email' => ProductionEnvironmentWriter::DEDICATED_SMOKE_EMAIL]);

        $this->artisan('grindflow:provision-smoke-user')->assertSuccessful();
        $this->artisan('grindflow:provision-smoke-user')->assertSuccessful();

        $legacy->refresh();
        self::assertSame(UserRole::Model, $legacy->platform_role);
        self::assertSame('Legacy synthetic member', $legacy->name);
        self::assertSame($legacyHash, $legacy->getAuthPassword());
        self::assertSame($legacyUpdatedAt, $legacy->updated_at?->format('Y-m-d H:i:s.u'));
        self::assertSame(1, $legacy->memberships()->count());
        $dedicated = User::query()->where('email', ProductionEnvironmentWriter::DEDICATED_SMOKE_EMAIL)->sole();
        self::assertSame(UserRole::Admin, $dedicated->platform_role);
        self::assertSame(0, $dedicated->memberships()->count());
        $this->assertDatabaseCount('users', 2);
        self::assertCount(1, Storage::disk('local')->allFiles('operations/smoke-user-backups'));
    }

    public function test_it_refuses_to_reconcile_a_synthetic_email_with_an_organization_membership(): void
    {
        $user = User::factory()->create([
            'name' => 'Previously associated identity',
            'email' => 'e2e-admin@grindflow.test',
            'password' => 'original-secret',
            'email_verified_at' => null,
            'platform_role' => UserRole::Model,
        ]);
        $organization = Organization::factory()->create();
        Membership::query()->create([
            'user_id' => $user->getKey(),
            'organization_id' => $organization->getKey(),
            'role' => UserRole::Model,
        ]);
        $hashBefore = $user->getAuthPassword();
        $updatedAtBefore = $user->updated_at?->format('Y-m-d H:i:s.u');

        $this->artisan('grindflow:provision-smoke-user')
            ->expectsOutputToContain('Synthetic smoke identity reconciliation failed safely.')
            ->assertFailed();
        self::assertSame('provision-membership-conflict', config('grindflow.smoke_provision_failure_code'));

        $user->refresh();
        self::assertSame(UserRole::Model, $user->platform_role);
        self::assertNull($user->email_verified_at);
        self::assertSame('Previously associated identity', $user->name);
        self::assertSame($hashBefore, $user->getAuthPassword());
        self::assertSame($updatedAtBefore, $user->updated_at?->format('Y-m-d H:i:s.u'));
        self::assertTrue(Hash::check('original-secret', $user->getAuthPassword()));
        self::assertSame(1, Membership::query()->where('user_id', $user->getKey())->count());
        self::assertSame([], Storage::disk('local')->allFiles('operations/smoke-user-backups'));
    }

    public function test_it_rejects_a_membership_even_when_the_synthetic_account_is_already_reconciled(): void
    {
        $this->artisan('grindflow:provision-smoke-user')->assertSuccessful();

        $user = User::query()->sole();
        $organization = Organization::factory()->create();
        Membership::query()->create([
            'user_id' => $user->getKey(),
            'organization_id' => $organization->getKey(),
            'role' => UserRole::Model,
        ]);
        $hashBefore = $user->getAuthPassword();
        $backupFilesBefore = Storage::disk('local')->allFiles('operations/smoke-user-backups');

        $this->artisan('grindflow:provision-smoke-user')
            ->expectsOutputToContain('Synthetic smoke identity reconciliation failed safely.')
            ->assertFailed();
        self::assertSame('provision-membership-conflict', config('grindflow.smoke_provision_failure_code'));

        $user->refresh();
        self::assertSame(UserRole::Admin, $user->platform_role);
        self::assertSame($hashBefore, $user->getAuthPassword());
        self::assertSame(1, Membership::query()->where('user_id', $user->getKey())->count());
        self::assertSame($backupFilesBefore, Storage::disk('local')->allFiles('operations/smoke-user-backups'));
    }

    public function test_command_output_never_contains_identity_or_secret(): void
    {
        self::assertSame(0, Artisan::call('grindflow:provision-smoke-user'));

        $output = Artisan::output();

        self::assertStringNotContainsString('e2e-admin@grindflow.test', $output);
        self::assertStringNotContainsString('synthetic-secret-with-safe-length', $output);
        self::assertDoesNotMatchRegularExpression('/[$]2[ayb][$][0-9]{2}[$]/', $output);
    }
}
