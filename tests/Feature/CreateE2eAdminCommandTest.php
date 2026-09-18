<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CreateE2eAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_an_admin_that_can_log_in(): void
    {
        $exitCode = Artisan::call('grindflow:e2e-admin');

        $this->assertSame(0, $exitCode);

        $output = Artisan::output();

        preg_match('/Password:\s+(\S+)/', $output, $matches);
        $password = $matches[1] ?? null;

        $this->assertNotNull($password);

        $user = User::query()
            ->where('email', 'e2e-admin@grindflow.test')
            ->firstOrFail();

        $organization = Organization::query()
            ->where('slug', 'e2e-admin-workspace')
            ->firstOrFail();

        $membership = Membership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertSame(UserRole::Admin, $user->platform_role);
        $this->assertSame(UserRole::Admin, $membership->role);

        $this->post('/login', [
            'email' => $user->email,
            'password' => $password,
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_rerunning_command_rotates_password_without_duplicate_records(): void
    {
        Artisan::call('grindflow:e2e-admin');
        $firstOutput = Artisan::output();

        preg_match('/Password:\s+(\S+)/', $firstOutput, $firstMatches);
        $firstPassword = $firstMatches[1] ?? null;

        Artisan::call('grindflow:e2e-admin');
        $secondOutput = Artisan::output();

        preg_match('/Password:\s+(\S+)/', $secondOutput, $secondMatches);
        $secondPassword = $secondMatches[1] ?? null;

        $this->assertNotNull($firstPassword);
        $this->assertNotNull($secondPassword);
        $this->assertNotSame($firstPassword, $secondPassword);

        $this->assertSame(
            1,
            User::query()->where('email', 'e2e-admin@grindflow.test')->count(),
        );
        $this->assertSame(
            1,
            Organization::query()->where('slug', 'e2e-admin-workspace')->count(),
        );
        $this->assertSame(1, Membership::query()->count());
    }
}
