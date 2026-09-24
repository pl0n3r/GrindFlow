<?php

declare(strict_types=1);

namespace Tests\Feature;

use JsonException;
use PHPUnit\Framework\TestCase;

final class PrivacyAsCodeTest extends TestCase
{
    private const PLACEHOLDER = '[COMPLETAR POR EL DUEÑO]';

    private const FACTORY_SHA = 'a14f38d5b4b1bb0db21101021375c76f1e36699c';

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
        self::assertSame(['supabase'], $this->treatment($data, 'media_vault')['providers']);
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
            'politica-tratamiento.md' => '9406b4890130bb338c4dbff0ec3f4803da4c3560d1ae834c3a9e7ed264e3ce3a',
            'registro-tratamientos.md' => 'ff99c96491876ddf7a6c6dc962802b0ee67c4e1a88eb7b5115c8723fb39eeaae',
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
        self::assertStringContainsString('private const DEDUPE_RETENTION_HOURS = 24;', $recorder);
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
