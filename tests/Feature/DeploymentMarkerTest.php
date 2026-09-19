<?php

namespace Tests\Feature;

use Tests\TestCase;

class DeploymentMarkerTest extends TestCase
{
    public function test_public_marker_reports_only_the_human_release_without_remote_sha(): void
    {
        $this->get('/_deployment')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, max-age=0')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertExactJson([
                'version' => (string) config('version.number'),
                'exact' => false,
                'commit' => null,
                'source' => 'release-only',
            ]);
    }

    public function test_public_marker_rejects_mutation_and_exposes_no_runtime_secrets(): void
    {
        $this->post('/_deployment')->assertStatus(405);
        $this->get('/_deployment')
            ->assertDontSee('APP_KEY')
            ->assertDontSee('DB_PASSWORD');
    }
}
