<?php

declare(strict_types=1);

namespace Tests\Feature;

use JsonException;
use PHPUnit\Framework\TestCase;

final class PrivacyAsCodeTest extends TestCase
{
    private const PLACEHOLDER = '[COMPLETAR POR EL DUEÑO]';

    private const FACTORY_SHA = 'a33b04cafeabfe0004f01fdf79291a0645a60f0a';

    public function test_data_map_matches_observed_grind_flow_treatments(): void
    {
        $data = $this->data();

        self::assertSame('pl0n3r/GrindFlow', $data['project']);
        self::assertSame('construccion', $data['phase']);
        self::assertSame(
            [
                'account_credentials',
                'account_identity',
                'cloud_connections',
                'legacy_supabase_link_clicks',
                'legacy_supabase_platform_credentials',
                'legacy_supabase_upload_audit',
                'media_vault',
                'organization_membership',
                'traffic_dedupe',
                'traffic_links',
                'traffic_metrics',
            ],
            $this->treatmentIds($data),
        );
        self::assertSame([self::PLACEHOLDER], array_values(array_unique($data['controller'])));

        $user = file_get_contents($this->root().'/app/Models/User.php');
        self::assertStringContainsString("'password' => 'hashed'", $user);
        self::assertStringContainsString('function memberships()', $user);

        $connections = file_get_contents(
            $this->root().'/database/migrations/2026_09_18_150000_create_media_connections_table.php',
        );
        foreach (['account_identifier', 'access_ciphertext', 'refresh_ciphertext', 'token_expires_at'] as $field) {
            self::assertStringContainsString($field, $connections);
        }

        $vault = file_get_contents(
            $this->root().'/database/migrations/2026_09_18_050000_create_media_vault_tables.php',
        );
        foreach (['original_filename', 'source_ref', 'metadata'] as $field) {
            self::assertStringContainsString($field, $vault);
        }

        $legacyIdentity = file_get_contents(
            $this->root().'/supabase/migrations/20260101000100_core_tenancy.sql',
        );
        foreach (['create table public.users', 'create table public.memberships', 'create table public.profiles'] as $table) {
            self::assertStringContainsString($table, $legacyIdentity);
        }
    }

    public function test_observed_providers_are_scoped_to_real_treatments(): void
    {
        $data = $this->data();

        self::assertSame(['supabase'], $this->treatment($data, 'account_identity')['providers']);
        self::assertSame(
            ['dropbox', 'google_drive', 'supabase'],
            $this->treatment($data, 'cloud_connections')['providers'],
        );
        self::assertSame(
            ['supabase'],
            $this->treatment($data, 'legacy_supabase_link_clicks')['providers'],
        );
        self::assertSame(
            ['supabase'],
            $this->treatment($data, 'legacy_supabase_platform_credentials')['providers'],
        );
        self::assertSame(
            ['supabase'],
            $this->treatment($data, 'legacy_supabase_upload_audit')['providers'],
        );
        self::assertSame(['cloudflare_r2', 'supabase'], $this->treatment($data, 'media_vault')['providers']);
        self::assertSame(['supabase'], $this->treatment($data, 'organization_membership')['providers']);
        self::assertSame(['supabase'], $this->treatment($data, 'traffic_links')['providers']);

        foreach (['account_credentials', 'traffic_dedupe', 'traffic_metrics'] as $id) {
            self::assertSame([], $this->treatment($data, $id)['providers']);
        }

        self::assertStringContainsString(
            'accounts.google.com',
            file_get_contents($this->root().'/app/Services/Media/Connections/GoogleOAuthClient.php'),
        );
        self::assertStringContainsString(
            'api.dropbox.com',
            file_get_contents($this->root().'/app/Services/Media/Connections/DropboxOAuthClient.php'),
        );

        $legacyConnection = file_get_contents($this->root().'/src/lib/connectors/connection.ts');
        self::assertStringContainsString("from '@/lib/supabase/service'", $legacyConnection);
        self::assertStringContainsString("from('cloud_connections')", $legacyConnection);

        $legacyIngest = file_get_contents($this->root().'/src/workers/ingest/ingest.ts');
        self::assertStringContainsString("from('media_assets')", $legacyIngest);
        self::assertStringContainsString("from('cloud_ingest_items')", $legacyIngest);
        self::assertStringContainsString("from '@/lib/r2'", $legacyIngest);
        self::assertStringContainsString('uploadStream({', $legacyIngest);
        self::assertStringContainsString('r2_key: key', $legacyIngest);

        $legacyPublish = file_get_contents($this->root().'/src/workers/publish/publish.ts');
        self::assertStringContainsString("from('platform_credentials')", $legacyPublish);
        self::assertStringContainsString("from('tracking_links')", $legacyPublish);

        $legacyUpload = file_get_contents($this->root().'/src/app/api/uploads/presign/route.ts');
        self::assertStringContainsString("from '@/lib/supabase/service'", $legacyUpload);
        self::assertStringContainsString("from('upload_link_events')", $legacyUpload);

        $supabaseUpload = file_get_contents(
            $this->root().'/supabase/migrations/20260101000300_media_and_uploads.sql',
        );
        self::assertStringContainsString('create table public.upload_link_events', $supabaseUpload);
        self::assertStringContainsString('ip               inet', $supabaseUpload);

        $supabaseCredentials = file_get_contents(
            $this->root().'/supabase/migrations/20260101000500_distribution_and_jobs.sql',
        );
        self::assertStringContainsString('create table public.platform_credentials', $supabaseCredentials);
        self::assertStringContainsString('secret_ciphertext', $supabaseCredentials);

        $supabaseTracking = file_get_contents(
            $this->root().'/supabase/migrations/20260101000600_tracking_links.sql',
        );
        self::assertStringContainsString('create table public.link_clicks', $supabaseTracking);
        self::assertStringContainsString('ua_family', $supabaseTracking);
    }

    public function test_generated_privacy_documents_are_current(): void
    {
        $expected = [
            'politica-tratamiento.md' => 'fb974d045d702f7466eaf1daa30dd31f958976f1adfce127b9f94e37b9aee636',
            'aviso-privacidad.md' => '4bd1cb1057fa4038940e8e285392df12743d77892ea51eae34ff448ce592dcd7',
            'terminos-condiciones.md' => 'a8bab030d4056482b7153d17695ec119f0715805d430130e27dc4a3fa7b64840',
            'registro-tratamientos.md' => '23c48eb228530decc39b843323fa4676d7f480c46f9b7aa6db89a7ae9ddccd65',
            'canal-derechos.md' => 'd8568464c8cd9bc04140846ee9dd30d65b3f5cb1564a387f10f268bede03e297',
            'retencion.md' => '27afabf1ea5af1da5ef3c0eea9fc093d950e455e266c5a83da8a535377abeec5',
        ];

        foreach ($expected as $name => $sha256) {
            self::assertSame(
                $sha256,
                hash_file('sha256', $this->root().'/docs/privacidad/'.$name),
                $name.' no coincide con la salida determinista del Factory.',
            );
        }
    }

    public function test_privacy_workflows_use_pinned_factory_with_minimum_permissions(): void
    {
        $privacy = file_get_contents($this->root().'/.github/workflows/privacidad.yml');
        $audit = file_get_contents($this->root().'/.github/workflows/auditoria-privacidad.yml');

        self::assertStringContainsString(
            'uses: pl0n3r/factory/.github/workflows/privacidad.yml@'.self::FACTORY_SHA,
            $privacy,
        );
        self::assertStringContainsString('kit_ref: '.self::FACTORY_SHA, $privacy);
        self::assertStringContainsString("permissions:\n  contents: read", $privacy);
        self::assertStringNotContainsString('issues: write', $privacy);
        self::assertStringNotContainsString('secrets:', $privacy);

        $ci = file_get_contents($this->root().'/.github/workflows/grindflow-ci.yml');
        self::assertStringContainsString('repository: pl0n3r/factory', $ci);
        self::assertStringContainsString('ref: '.self::FACTORY_SHA, $ci);
        self::assertStringContainsString('path: .factory', $ci);
        self::assertStringContainsString(
            "PRIVACY_BASE_SHA: \${{ github.event_name == 'pull_request' && github.event.pull_request.base.sha || github.event_name == 'push' && github.event.before || github.sha }}",
            $ci,
        );
        self::assertStringContainsString(
            'if [[ "${{ github.event_name }}" == "workflow_dispatch" ]]; then',
            $ci,
        );
        self::assertStringContainsString(
            'if [[ "$GITHUB_REF_NAME" == "$default_branch" ]]; then',
            $ci,
        );
        self::assertStringContainsString(
            'PRIVACY_BASE_SHA="$(git rev-parse HEAD^)"',
            $ci,
        );
        self::assertStringContainsString(
            'PRIVACY_BASE_SHA="$(git merge-base "origin/$default_branch" HEAD)"',
            $ci,
        );
        self::assertStringContainsString(
            'elif [[ "$PRIVACY_BASE_SHA" == "0000000000000000000000000000000000000000" ]]; then',
            $ci,
        );
        self::assertStringContainsString(
            'PRIVACY_BASE_SHA="$(git rev-list --max-parents=0 HEAD | tail -1)"',
            $ci,
        );
        self::assertStringContainsString(
            'PYTHONPATH=.factory python3 .factory/scripts/privacidad_gate.py --base-sha "$PRIVACY_BASE_SHA"',
            $ci,
        );
        self::assertStringContainsString(
            'needs: [preflight, fast, php-quality, tests, database, browser, realstack, legacy, symfony-preview, privacy]',
            $ci,
        );
        self::assertStringContainsString('PRIVACY_RESULT: ${{ needs.privacy.result }}', $ci);
        self::assertStringContainsString('require_success privacy "$PRIVACY_RESULT"', $ci);

        self::assertStringContainsString(
            'uses: pl0n3r/factory/.github/workflows/auditoria-privacidad.yml@'.self::FACTORY_SHA,
            $audit,
        );
        self::assertStringContainsString('kit_ref: '.self::FACTORY_SHA, $audit);
        self::assertStringContainsString("permissions:\n  contents: read\n  issues: write", $audit);
        self::assertStringNotContainsString('contents: write', $audit);
        self::assertStringNotContainsString('secrets:', $audit);
    }

    public function test_pending_legal_states_are_not_invented(): void
    {
        $data = $this->data();

        self::assertSame('construccion', $data['phase']);
        self::assertSame([self::PLACEHOLDER], array_values(array_unique($data['controller'])));

        foreach ($data['treatments'] as $treatment) {
            self::assertSame('review_required', $treatment['basis']);
            self::assertSame('review_required', $treatment['consent']);
            self::assertSame(
                $treatment['id'] === 'traffic_dedupe' ? 'dedupe_24h' : 'review_required',
                $treatment['retention'],
            );
        }

        $policy = file_get_contents($this->root().'/docs/privacidad/politica-tratamiento.md');
        self::assertStringContainsString('no constituye aprobación jurídica', $policy);
        self::assertStringNotContainsString('jurídicamente aprobada', $policy);
    }

    public function test_traffic_fingerprint_documents_hash_and_observed_retention(): void
    {
        $data = $this->data();
        $dedupe = $this->treatment($data, 'traffic_dedupe');

        self::assertSame(['visitor_hash', 'last_counted_at'], $dedupe['fields']);
        self::assertSame('dedupe_24h', $dedupe['retention']);
        self::assertNotContains('ip', $dedupe['fields']);

        $fingerprint = file_get_contents(
            $this->root().'/app/Services/Traffic/VisitorFingerprint.php',
        );
        self::assertStringContainsString('$request->ip()', $fingerprint);
        self::assertStringContainsString('hash_hmac(', $fingerprint);

        $recorder = file_get_contents(
            $this->root().'/app/Services/Traffic/TrafficAttributionRecorder.php',
        );
        self::assertStringContainsString('private const int DEDUPE_RETENTION_HOURS = 24;', $recorder);
        self::assertStringContainsString("'visitor_hash' => \$visitorHash", $recorder);

        $trafficMigration = file_get_contents(
            $this->root().'/database/migrations/2026_09_19_033000_create_traffic_attribution_tables.php',
        );
        self::assertStringContainsString("\$table->char('visitor_hash', 64);", $trafficMigration);

        $legacyAudit = $this->treatment($data, 'legacy_supabase_upload_audit');
        self::assertContains('ip', $legacyAudit['fields']);
        self::assertSame(['supabase'], $legacyAudit['providers']);

        $legacyClicks = $this->treatment($data, 'legacy_supabase_link_clicks');
        self::assertNotContains('ip', $legacyClicks['fields']);
        self::assertContains('referrer', $legacyClicks['fields']);
        self::assertContains('ua_family', $legacyClicks['fields']);
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        try {
            return json_decode(
                file_get_contents($this->root().'/datos.yml'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            self::fail('datos.yml no es JSON/YAML canónico válido: '.$exception->getMessage());
        }
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @param array<string, mixed> $data
     * @return list<string>
     */
    private function treatmentIds(array $data): array
    {
        $ids = array_map(
            static fn (array $treatment): string => $treatment['id'],
            $data['treatments'],
        );
        sort($ids);

        return $ids;
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function treatment(array $data, string $id): array
    {
        foreach ($data['treatments'] as $treatment) {
            if ($treatment['id'] === $id) {
                return $treatment;
            }
        }

        self::fail('Tratamiento no encontrado: '.$id);
    }
}
