<?php

declare(strict_types=1);

namespace Tests\Feature;

use JsonException;
use PHPUnit\Framework\TestCase;

final class PrivacyAsCodeTest extends TestCase
{
    private const PLACEHOLDER = '[COMPLETAR POR EL DUEÑO]';
    private const FACTORY_SHA = 'a14f38d5b4b1bb0db21101021375c76f1e36699c';

    public function testDataMapMatchesObservedGrindFlowTreatments(): void
    {
        $data = $this->data();

        self::assertSame('pl0n3r/GrindFlow', $data['project']);
        self::assertSame('construccion', $data['phase']);
        self::assertSame(
            [
                'account_credentials',
                'account_identity',
                'cloud_connections',
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
    }

    public function testObservedProvidersAreScopedToRealTreatments(): void
    {
        $data = $this->data();

        self::assertSame(
            ['dropbox', 'google_drive'],
            $this->treatment($data, 'cloud_connections')['providers'],
        );
        self::assertSame(
            ['supabase'],
            $this->treatment($data, 'legacy_supabase_upload_audit')['providers'],
        );

        foreach ($data['treatments'] as $treatment) {
            if (in_array($treatment['id'], ['cloud_connections', 'legacy_supabase_upload_audit'], true)) {
                continue;
            }
            self::assertSame([], $treatment['providers']);
        }

        self::assertStringContainsString(
            'accounts.google.com',
            file_get_contents($this->root().'/app/Services/Media/Connections/GoogleOAuthClient.php'),
        );
        self::assertStringContainsString(
            'api.dropbox.com',
            file_get_contents($this->root().'/app/Services/Media/Connections/DropboxOAuthClient.php'),
        );

        $legacyUpload = file_get_contents($this->root().'/src/app/api/uploads/presign/route.ts');
        self::assertStringContainsString("from '@/lib/supabase/service'", $legacyUpload);
        self::assertStringContainsString("from('upload_link_events')", $legacyUpload);

        $supabaseMigration = file_get_contents(
            $this->root().'/supabase/migrations/20260101000300_media_and_uploads.sql',
        );
        self::assertStringContainsString('create table public.upload_link_events', $supabaseMigration);
        self::assertStringContainsString('ip               inet', $supabaseMigration);
    }

    public function testGeneratedPrivacyDocumentsAreCurrent(): void
    {
        $expected = [
            'politica-tratamiento.md' => '073dc490829e2655c76f5e70eb21726e441cc900b86684e17718ffdaedb63549',
            'registro-tratamientos.md' => 'be256f04ae631aa466d29810ecca95d77c6949247dae82cc2f17aca29e2a4d54',
            'retencion.md' => 'd2244ac11054ea1d4ec2a3c378395a08bfc365406adace803bc85657be4338bd',
        ];

        foreach ($expected as $name => $sha256) {
            self::assertSame(
                $sha256,
                hash_file('sha256', $this->root().'/docs/privacidad/'.$name),
                $name.' no coincide con la salida determinista del Factory.',
            );
        }
    }

    public function testPrivacyWorkflowsUsePinnedFactoryWithMinimumPermissions(): void
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

    public function testPendingLegalStatesAreNotInvented(): void
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

    public function testTrafficFingerprintDocumentsHashAndObservedRetention(): void
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
     *  @return list<string>
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
     *  @return array<string, mixed>
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
