<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Diagnostics\DiagnosticLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use RuntimeException;
use Tests\TestCase;

class DiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        foreach (glob(storage_path('logs/diagnostics-*.jsonl*')) ?: [] as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_diagnostic_log_sanitizes_server_failure_context(): void
    {
        $request = Request::create(
            '/dashboard',
            'POST',
            ['password' => 'request-body-secret'],
        );

        $incidentId = app(DiagnosticLog::class)->record(
            new RuntimeException(
                'Database failed password=super-secret mysql://user:pass@localhost/grindflow',
            ),
            $request,
        );

        $entry = app(DiagnosticLog::class)->recent(1)[0];

        $this->assertSame($incidentId, $entry['incident_id']);
        $this->assertSame('/dashboard', $entry['request']['path']);
        $this->assertSame('POST', $entry['request']['method']);
        $this->assertStringNotContainsString('super-secret', $entry['message']);
        $this->assertStringNotContainsString('user:pass', $entry['message']);
        $this->assertStringNotContainsString(
            'request-body-secret',
            json_encode($entry, JSON_THROW_ON_ERROR),
        );
        $this->assertStringContainsString('[redacted]', $entry['message']);
    }

    public function test_platform_admin_can_read_recent_diagnostics(): void
    {
        $incidentId = app(DiagnosticLog::class)->record(
            new RuntimeException('Synthetic diagnostics failure'),
            Request::create('/dashboard', 'GET'),
        );

        $admin = User::factory()->create([
            'platform_role' => UserRole::Admin,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.diagnostics.json'))
            ->assertOk()
            ->assertJsonPath('entries.0.incident_id', $incidentId)
            ->assertJsonPath('entries.0.message', 'Synthetic diagnostics failure');
    }

    public function test_non_admin_cannot_read_diagnostics(): void
    {
        $user = User::factory()->create([
            'platform_role' => UserRole::Model,
        ]);

        $this->actingAs($user)
            ->get(route('admin.diagnostics'))
            ->assertForbidden();

        $this->get(route('admin.diagnostics.json'))
            ->assertForbidden();
    }
}
