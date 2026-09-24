<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Deployment\CheckoutIdentity;
use App\Support\Deployment\GitHubActionsOidcVerifier;
use App\Support\Deployment\ProductionEnvironmentWriter;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductionSmokeBootstrapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->detectEnvironment(static fn (): string => 'production');
        Storage::fake('local');
        $identity = Mockery::mock(CheckoutIdentity::class);
        $identity->shouldReceive('commit')->andReturn(str_repeat('a', 40));
        $this->app->instance(CheckoutIdentity::class, $identity);

        config([
            'grindflow.phase' => 'construccion',
            'cache.limiter' => 'array',
            'grindflow.smoke_user.email' => 'e2e-admin@grindflow.test',
            'grindflow.smoke_user.password' => '',
            'grindflow.smoke_user.name' => 'GrindFlow Production Smoke',
        ]);
    }

    public function test_verified_oidc_bootstrap_reconciles_the_synthetic_account_without_exposing_secret(): void
    {
        $sha = str_repeat('a', 40);
        $password = 'workflow-secret-value';

        $verifier = Mockery::mock(GitHubActionsOidcVerifier::class);
        $verifier->shouldReceive('verify')
            ->once()
            ->with('signed-oidc-token', $sha)
            ->andReturn(['sha' => $sha]);
        $this->app->instance(GitHubActionsOidcVerifier::class, $verifier);

        $writer = Mockery::mock(ProductionEnvironmentWriter::class);
        $writer->shouldReceive('withSmokePassword')
            ->once()
            ->with($password, Mockery::type(Closure::class))
            ->andReturnUsing(static function (string $secret, Closure $afterPersist): void {
                $afterPersist();
            });
        $this->app->instance(ProductionEnvironmentWriter::class, $writer);

        $this->artisan('config:clear')->assertSuccessful();

        $response = $this->withHeader('Authorization', 'Bearer signed-oidc-token')
            ->withHeader('X-GrindFlow-Expected-Sha', $sha)
            ->postJson('/internal/production-smoke/bootstrap', [
                'password' => $password,
            ]);

        $response->assertNoContent()
            ->assertHeader('Cache-Control');

        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('max-age=0', $cacheControl);

        $user = User::query()->sole();
        self::assertSame(UserRole::Admin, $user->platform_role);
        self::assertSame('e2e-admin@grindflow.test', $user->email);
        self::assertTrue(Hash::check($password, $user->password));
        self::assertStringNotContainsString($password, (string) $response->getContent());
    }

    public function test_bootstrap_is_not_found_outside_construction_phase(): void
    {
        config(['grindflow.phase' => 'operacion']);

        $verifier = Mockery::mock(GitHubActionsOidcVerifier::class);
        $verifier->shouldNotReceive('verify');
        $this->app->instance(GitHubActionsOidcVerifier::class, $verifier);

        $this->withHeader('Authorization', 'Bearer signed-oidc-token')
            ->withHeader('X-GrindFlow-Expected-Sha', str_repeat('a', 40))
            ->postJson('/internal/production-smoke/bootstrap', ['password' => 'unused'])
            ->assertNotFound();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_bootstrap_is_not_found_outside_production(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'staging');

        $verifier = Mockery::mock(GitHubActionsOidcVerifier::class);
        $verifier->shouldNotReceive('verify');
        $this->app->instance(GitHubActionsOidcVerifier::class, $verifier);

        $this->withHeader('Authorization', 'Bearer signed-oidc-token')
            ->withHeader('X-GrindFlow-Expected-Sha', str_repeat('a', 40))
            ->postJson('/internal/production-smoke/bootstrap', ['password' => 'unused'])
            ->assertNotFound();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_bootstrap_returns_503_without_database_write_when_reconciliation_fails(): void
    {
        $sha = str_repeat('a', 40);

        $verifier = Mockery::mock(GitHubActionsOidcVerifier::class);
        $verifier->shouldReceive('verify')
            ->once()
            ->with('signed-oidc-token', $sha)
            ->andReturn(['sha' => $sha]);
        $this->app->instance(GitHubActionsOidcVerifier::class, $verifier);

        $writer = Mockery::mock(ProductionEnvironmentWriter::class);
        $writer->shouldReceive('withSmokePassword')
            ->once()
            ->andThrow(new \RuntimeException('synthetic persistence failure'));
        $this->app->instance(ProductionEnvironmentWriter::class, $writer);

        $response = $this->withHeader('Authorization', 'Bearer signed-oidc-token')
            ->withHeader('X-GrindFlow-Expected-Sha', $sha)
            ->postJson('/internal/production-smoke/bootstrap', ['password' => 'unused']);

        $response->assertStatus(503);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertDatabaseCount('users', 0);
    }

    #[DataProvider('safeReconciliationCodes')]
    public function test_verified_bootstrap_reports_only_allowlisted_failure_codes(
        string $errorMessage,
        string $expectedCode,
    ): void {
        $sha = str_repeat('a', 40);
        $verifier = Mockery::mock(GitHubActionsOidcVerifier::class);
        $verifier->shouldReceive('verify')->once()
            ->with('signed-oidc-token', $sha)->andReturn(['sha' => $sha]);
        $this->app->instance(GitHubActionsOidcVerifier::class, $verifier);

        $writer = Mockery::mock(ProductionEnvironmentWriter::class);
        $writer->shouldReceive('withSmokePassword')->once()
            ->andThrow(new \RuntimeException($errorMessage));
        $this->app->instance(ProductionEnvironmentWriter::class, $writer);

        $response = $this->withHeader('Authorization', 'Bearer signed-oidc-token')
            ->withHeader('X-GrindFlow-Expected-Sha', $sha)
            ->postJson('/internal/production-smoke/bootstrap', ['password' => 'test-workflow-secret']);

        $response->assertStatus(503)
            ->assertHeader('X-GrindFlow-Smoke-Failure-Stage', 'environment')
            ->assertHeader('X-GrindFlow-Smoke-Failure-Code', $expectedCode);
        self::assertSame('', $response->getContent());
        self::assertStringNotContainsString($errorMessage, (string) $response->headers);
        $this->assertDatabaseCount('users', 0);
    }

    public static function safeReconciliationCodes(): array
    {
        return [
            'environment not writable' => ['Production environment file is unavailable.', 'env-unavailable'],
            'backup not writable' => ['Unable to write production environment backup.', 'backup-write-failed'],
            'lock inaccessible' => ['Unable to open production environment lock.', 'lock-unavailable'],
            'no raw exception or secret reflection' => ['SECRET=private-failure-detail', 'unexpected'],
        ];
    }

    public function test_verified_bootstrap_classifies_invalid_writer_password_without_mutating_environment(): void
    {
        $sha = str_repeat('a', 40);
        $verifier = Mockery::mock(GitHubActionsOidcVerifier::class);
        $verifier->shouldReceive('verify')->once()
            ->with('signed-oidc-token', $sha)->andReturn(['sha' => $sha]);
        $this->app->instance(GitHubActionsOidcVerifier::class, $verifier);

        // Keep the real writer: its input guard executes before opening .env.
        $password = "invalid\\nsynthetic-secret";
        $response = $this->withHeader('Authorization', 'Bearer signed-oidc-token')
            ->withHeader('X-GrindFlow-Expected-Sha', $sha)
            ->postJson('/internal/production-smoke/bootstrap', ['password' => $password]);

        $response->assertStatus(503)
            ->assertHeader('X-GrindFlow-Smoke-Failure-Stage', 'environment')
            ->assertHeader('X-GrindFlow-Smoke-Failure-Code', 'password-invalid');
        self::assertSame('', $response->getContent());
        self::assertStringNotContainsString($password, (string) $response->headers);
        $this->assertDatabaseCount('users', 0);
    }

    #[DataProvider('failedReconciliationStages')]
    public function test_verified_bootstrap_identifies_failed_command_without_reflecting_errors(
        string $failedCommand,
        string $expectedStage,
        string $expectedCode,
    ): void {
        $sha = str_repeat('a', 40);
        $verifier = Mockery::mock(GitHubActionsOidcVerifier::class);
        $verifier->shouldReceive('verify')->once()
            ->with('signed-oidc-token', $sha)->andReturn(['sha' => $sha]);
        $this->app->instance(GitHubActionsOidcVerifier::class, $verifier);

        $writer = Mockery::mock(ProductionEnvironmentWriter::class);
        $writer->shouldReceive('withSmokePassword')->once()
            ->andReturnUsing(static function (string $secret, Closure $afterPersist): void {
                $afterPersist();
            });
        $this->app->instance(ProductionEnvironmentWriter::class, $writer);

        Artisan::shouldReceive('call')
            ->once()->with('config:clear')
            ->andReturn($failedCommand === 'config:clear' ? 1 : 0);

        if ($failedCommand === 'grindflow:provision-smoke-user') {
            Artisan::shouldReceive('call')->once()
                ->with('grindflow:provision-smoke-user')->andReturn(1);
        }

        $response = $this->withHeader('Authorization', 'Bearer signed-oidc-token')
            ->withHeader('X-GrindFlow-Expected-Sha', $sha)
            ->postJson('/internal/production-smoke/bootstrap', ['password' => 'synthetic-secret']);

        $response->assertStatus(503)
            ->assertHeader('X-GrindFlow-Smoke-Failure-Stage', $expectedStage)
            ->assertHeader('X-GrindFlow-Smoke-Failure-Code', $expectedCode);
        self::assertSame('', $response->getContent());
        $this->assertDatabaseCount('users', 0);
    }

    public static function failedReconciliationStages(): array
    {
        return [
            'config invalidation' => ['config:clear', 'config-clear', 'config-clear-failed'],
            'synthetic command' => ['grindflow:provision-smoke-user', 'provision-user', 'provision-failed'],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function test_bootstrap_rejects_invalid_payload_before_oidc(array $payload, string $sha): void
    {
        $verifier = Mockery::mock(GitHubActionsOidcVerifier::class);
        $verifier->shouldNotReceive('verify');
        $this->app->instance(GitHubActionsOidcVerifier::class, $verifier);

        $writer = Mockery::mock(ProductionEnvironmentWriter::class);
        $writer->shouldNotReceive('withSmokePassword');
        $this->app->instance(ProductionEnvironmentWriter::class, $writer);

        $this->withHeader('Authorization', 'Bearer signed-oidc-token')
            ->withHeader('X-GrindFlow-Expected-Sha', $sha)
            ->postJson('/internal/production-smoke/bootstrap', $payload)
            ->assertForbidden();

        $this->assertDatabaseCount('users', 0);
    }

    public static function invalidPayloads(): array
    {
        $sha = str_repeat('a', 40);

        return [
            'missing password' => [[], $sha],
            'empty password' => [['password' => ''], $sha],
            'array password' => [['password' => ['not-a-string']], $sha],
            'numeric password' => [['password' => 1234], $sha],
            'uppercase sha' => [['password' => 'unused'], str_repeat('A', 40)],
            'short sha' => [['password' => 'unused'], 'abc'],
        ];
    }

    public function test_bootstrap_rejects_missing_oidc_before_any_database_write(): void
    {
        $sha = str_repeat('a', 40);

        $this->withHeader('X-GrindFlow-Expected-Sha', $sha)
            ->postJson('/internal/production-smoke/bootstrap', [
                'password' => 'never-used-secret',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_bootstrap_rejects_invalid_oidc_before_environment_or_database_write(): void
    {
        $sha = str_repeat('a', 40);

        $verifier = Mockery::mock(GitHubActionsOidcVerifier::class);
        $verifier->shouldReceive('verify')
            ->once()
            ->with('invalid-signed-token', $sha)
            ->andThrow(new \RuntimeException('invalid token'));
        $this->app->instance(GitHubActionsOidcVerifier::class, $verifier);

        $writer = Mockery::mock(ProductionEnvironmentWriter::class);
        $writer->shouldNotReceive('withSmokePassword');
        $this->app->instance(ProductionEnvironmentWriter::class, $writer);

        $this->withHeader('Authorization', 'Bearer invalid-signed-token')
            ->withHeader('X-GrindFlow-Expected-Sha', $sha)
            ->postJson('/internal/production-smoke/bootstrap', [
                'password' => 'never-used-secret',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_bootstrap_rejects_oidc_for_a_sha_not_deployed_on_the_server(): void
    {
        $sha = str_repeat('b', 40);
        $verifier = Mockery::mock(GitHubActionsOidcVerifier::class);
        $verifier->shouldNotReceive('verify');
        $this->app->instance(GitHubActionsOidcVerifier::class, $verifier);

        $this->withHeader('Authorization', 'Bearer signed-oidc-token')
            ->withHeader('X-GrindFlow-Expected-Sha', $sha)
            ->postJson('/internal/production-smoke/bootstrap', [
                'password' => 'never-used-secret',
            ])
            ->assertConflict();

        $this->assertDatabaseCount('users', 0);
    }
}
