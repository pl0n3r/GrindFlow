<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Diagnostics\DiagnosticLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
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
        $this->assertSame('/[unmatched]', $entry['request']['path']);
        $this->assertSame('POST', $entry['request']['method']);
        $this->assertStringNotContainsString('super-secret', $entry['message']);
        $this->assertStringNotContainsString('user:pass', $entry['message']);
        $this->assertStringNotContainsString(
            'request-body-secret',
            json_encode($entry, JSON_THROW_ON_ERROR),
        );
        $this->assertStringContainsString('[redacted]', $entry['message']);
    }

    public function test_diagnostic_log_redacts_guest_upload_token_route(): void
    {
        $request = Request::create('/guest-upload/SYNTHETIC-SECRET?query=SYNTHETIC-QUERY', 'GET');
        $route = new Route('GET', 'guest-upload/{token}', static fn () => null);
        $route->name('guest-upload.show');
        $request->setRouteResolver(static fn () => $route);

        $incidentId = app(DiagnosticLog::class)->record(new RuntimeException('Synthetic failure'), $request);
        $entry = app(DiagnosticLog::class)->recent(1)[0];
        $json = json_encode($entry, JSON_THROW_ON_ERROR);

        $this->assertSame($incidentId, $entry['incident_id']);
        $this->assertSame('/guest-upload/{token}', $entry['request']['path']);
        $this->assertSame('guest-upload.show', $entry['request']['route']);
        $this->assertSame('GET', $entry['request']['method']);
        $this->assertStringNotContainsString('SYNTHETIC-SECRET', $json);
        $this->assertStringNotContainsString('SYNTHETIC-QUERY', $json);
    }

    public function test_diagnostic_log_redacts_tracking_link_token_route(): void
    {
        $request = Request::create('/l/SYNTHETIC-LINK?source=SYNTHETIC-QUERY', 'GET');
        $route = new Route('GET', 'l/{token}', static fn () => null);
        $route->name('distribution.redirect');
        $request->setRouteResolver(static fn () => $route);

        app(DiagnosticLog::class)->record(new RuntimeException('Synthetic tracking failure'), $request);
        $entry = app(DiagnosticLog::class)->recent(1)[0];
        $json = json_encode($entry, JSON_THROW_ON_ERROR);

        $this->assertSame('/l/{token}', $entry['request']['path']);
        $this->assertSame('distribution.redirect', $entry['request']['route']);
        $this->assertStringNotContainsString('SYNTHETIC-LINK', $json);
        $this->assertStringNotContainsString('SYNTHETIC-QUERY', $json);
    }

    public function test_diagnostic_log_unmatched_route_never_exposes_raw_path(): void
    {
        $request = Request::create('/guest-upload/SYNTHETIC-UNKNOWN?secret=SYNTHETIC-QUERY', 'POST');
        app(DiagnosticLog::class)->record(new RuntimeException('Synthetic unmatched failure'), $request);
        $entry = app(DiagnosticLog::class)->recent(1)[0];
        $json = json_encode($entry, JSON_THROW_ON_ERROR);

        $this->assertSame('/[unmatched]', $entry['request']['path']);
        $this->assertNull($entry['request']['route']);
        $this->assertSame('POST', $entry['request']['method']);
        $this->assertStringNotContainsString('SYNTHETIC-UNKNOWN', $json);
        $this->assertStringNotContainsString('SYNTHETIC-QUERY', $json);
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
