<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeploymentMarkerTest extends TestCase
{
    public function test_public_marker_reports_only_the_human_release_without_remote_sha(): void
    {
        $response = $this->get('/_deployment');

        $response->assertOk()
            ->assertHeader('Cache-Control')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertExactJson([
                'version' => (string) config('version.number'),
                'exact' => false,
                'commit' => null,
                'source' => 'release-only',
            ]);

        $this->assertStringContainsString(
            'no-store',
            (string) $response->baseResponse->headers->get('Cache-Control'),
        );
    }

    public function test_marker_does_not_query_missing_database_session_table(): void
    {
        config([
            'session.driver' => 'database',
            'session.table' => 'missing_sessions_for_deployment_marker',
        ]);

        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        $this->get('/_deployment')
            ->assertOk()
            ->assertJsonPath('exact', false);

        $this->assertSame(0, $queries);
    }

    public function test_public_marker_rejects_mutation_and_exposes_no_runtime_secrets(): void
    {
        $this->post('/_deployment')->assertStatus(405);
        $this->get('/_deployment')
            ->assertDontSee('APP_KEY')
            ->assertDontSee('DB_PASSWORD');
    }
}
