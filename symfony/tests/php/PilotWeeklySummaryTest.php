<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class PilotWeeklySummaryTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testWeeklyPilotSummaryScopesEventsToMembershipWithoutInventingTraffic(): void
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);

        $client->request('GET', '/api/admin/pilot/weekly-summary');
        self::assertResponseStatusCodeSame(401);
        self::assertSame('authentication_required', $this->payload($client)['error']['code']);
        $client->request('GET', '/api/admin/pilot/weekly-summary.csv');
        self::assertResponseStatusCodeSame(401);
        self::assertSame('authentication_required', $this->payload($client)['error']['code']);

        $user = Uuid::v7()->toRfc4122();
        $mine = Uuid::v7()->toRfc4122();
        $other = Uuid::v7()->toRfc4122();
        $mineAsset = Uuid::v7()->toRfc4122();
        $otherAsset = Uuid::v7()->toRfc4122();
        $mineDraft = Uuid::v7()->toRfc4122();
        $otherDraft = Uuid::v7()->toRfc4122();
        $password = 'synthetic-pilot-password-123';
        $now = gmdate('Y-m-d H:i:s');
        $utc = new DateTimeZone('UTC');
        $week = (new DateTimeImmutable('now', $utc))->modify('monday this week')->setTime(0, 0);
        $thisWeek = $week->format('Y-m-d');
        $lastWeek = $week->modify('-7 days')->format('Y-m-d');
        // Relative future time keeps the upcoming dashboard assertion valid any weekday.
        $upcomingAt = (new DateTimeImmutable('now', $utc))->modify('+3 days')->setTime(10, 0);

        $db->insert('gf_identity_users', [
            'id' => $user,
            'name' => 'Lectura piloto',
            'email' => $user.'@example.test',
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'platform_role' => 'model',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([$mine => 'Piloto propio', $other => 'Piloto ajeno'] as $id => $name) {
            $db->insert('gf_identity_organizations', [
                'id' => $id,
                'name' => $name,
                'slug' => 'pilot-'.substr($id, 0, 27),
                'type' => 'independent',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $db->insert('gf_identity_memberships', [
            'id' => Uuid::v7()->toRfc4122(),
            'user_id' => $user,
            'organization_id' => $mine,
            'role' => 'model',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([[$mineAsset, $mine], [$otherAsset, $other]] as [$id, $organization]) {
            $db->insert('gf_vault_assets', [
                'id' => $id,
                'organization_id' => $organization,
                'uploaded_by' => $user,
                'original_name' => $organization === $mine ? 'own.png' : 'foreign.png',
                'mime_type' => 'image/png',
                'size_bytes' => 10,
                'sha256' => hash('sha256', $id),
                'storage_key' => $id,
                'usage_scope' => 'needs_review',
                'created_at' => $now,
            ]);
        }
        foreach ([[$mineDraft, $mine, $mineAsset], [$otherDraft, $other, $otherAsset]] as [$id, $organization, $asset]) {
            $db->insert('gf_schedule_drafts', [
                'id' => $id,
                'organization_id' => $organization,
                'asset_id' => $asset,
                'created_by' => $user,
                'scheduled_at_utc' => $upcomingAt->format('Y-m-d H:i:s'),
                'timezone' => 'UTC',
                'local_date' => $upcomingAt->format('Y-m-d'),
                'local_time' => '10:00',
                'status' => 'draft',
                'delivery_provider' => 'facebook_page',
                'delivery_destination_id' => '12345678',
                'created_at' => $now,
            ]);
        }

        foreach ([
            [$mine, $mineDraft, 'prepare', $thisWeek.' 00:00:00'],
            [$mine, $mineDraft, 'complete', $thisWeek.' 00:01:00'],
            [$mine, $mineDraft, 'fail', $lastWeek.' 10:00:00'],
            [$other, $otherDraft, 'prepare', $thisWeek.' 10:00:00'],
            [$other, $otherDraft, 'fail', $thisWeek.' 11:00:00'],
        ] as [$organization, $draft, $action, $created]) {
            $db->insert('gf_manual_handoff_events', [
                'id' => Uuid::v7()->toRfc4122(),
                'organization_id' => $organization,
                'draft_id' => $draft,
                'actor_id' => $user,
                'action' => $action,
                'created_at' => $created,
            ]);
        }


        // Synthetic S4 ledger data: an external result is not a manual handoff event.
        foreach ([[$mine, $mineDraft, 'published'], [$other, $otherDraft, 'rejected']] as [$organization, $draft, $status]) {
            $db->insert('gf_external_publication_attempts', [
                'id' => Uuid::v7()->toRfc4122(),
                'organization_id' => $organization,
                'provider' => 'facebook_page',
                'idempotency_key' => 'schedule-draft:'.$draft.':facebook_page:v1',
                'request_fingerprint' => hash('sha256', $organization.$draft),
                'status' => $status,
                'external_publication_id' => $status === 'published' ? 'synthetic-publication' : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Own-tenant orphan pointing at a foreign draft must never inflate an outcome.
        $db->insert('gf_external_publication_attempts', [
            'id' => Uuid::v7()->toRfc4122(),
            'organization_id' => $mine,
            'provider' => 'facebook_page',
            'idempotency_key' => 'schedule-draft:'.$otherDraft.':facebook_page:v1',
            'request_fingerprint' => hash('sha256', 'orphan-'.$otherDraft),
            'status' => 'ambiguous',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $login = $client->request('GET', '/login');
        $client->submit($login->filter('form.identity-form')->form([
            'email' => $user.'@example.test',
            'password' => $password,
        ]));
        $selector = $client->request('GET', '/organizations');
        $client->submit($selector->filter('.identity-orgs form')->form());

        $client->request('GET', '/api/admin/pilot/weekly-summary?week='.$thisWeek);
        self::assertResponseIsSuccessful();
        $data = $this->payload($client)['data'];
        self::assertTrue($data['ready']);
        self::assertSame($thisWeek, $data['week_start_utc']);
        self::assertCount(7, $data['days']);
        self::assertSame(
            ['prepared_attempts' => 1, 'completed_reports' => 1, 'failed_attempts' => 0],
            $data['totals'],
        );
        self::assertSame(1, $data['days'][0]['completed_reports']);
        self::assertFalse($data['traffic']['ready']);
        self::assertNull($data['traffic']['clicks']);
        self::assertNull($data['external_publications_verified']);
        self::assertFalse($data['provider_calls']);

        self::assertTrue($data['mvp']['content']['ready']);
        self::assertSame(1, $data['mvp']['content']['available_assets']);
        self::assertSame(1, $data['mvp']['content']['original_blobs']);
        self::assertTrue($data['mvp']['content']['eligibility_ready']);
        self::assertSame(0, $data['mvp']['content']['ready_to_schedule']);
        self::assertSame(10, $data['mvp']['content']['used_bytes']);
        self::assertTrue($data['mvp']['upcoming']['ready']);
        self::assertSame(1, $data['mvp']['upcoming']['total']);
        self::assertCount(1, $data['mvp']['upcoming']['items']);
        self::assertSame('own.png', $data['mvp']['upcoming']['items'][0]['asset_name']);
        self::assertSame(
            $upcomingAt->format('Y-m-d H:i:s'),
            $data['mvp']['upcoming']['items'][0]['scheduled_at_utc'],
        );
        self::assertTrue($data['mvp']['deliveries']['ready']);
        self::assertSame(1, $data['mvp']['deliveries']['counts']['published']);
        self::assertSame(0, $data['mvp']['deliveries']['counts']['rejected']);
        self::assertSame(0, $data['mvp']['deliveries']['counts']['ambiguous']);
        self::assertSame('published', $data['mvp']['deliveries']['recent'][0]['status']);
        self::assertSame('facebook_page', $data['mvp']['deliveries']['recent'][0]['provider']);
        self::assertSame('12345678', $data['mvp']['deliveries']['recent'][0]['destination']);
        self::assertFalse($data['mvp']['traffic']['ready']);
        self::assertNull($data['mvp']['traffic']['clicks']);


        // S4 requires both review and distribution authorization, as well as
        // a review-only weekly rule; storing an original is not eligibility.
        $db->insert('gf_content_rules', [
            'organization_id' => $mine,
            'timezone' => 'UTC',
            'weekdays' => 'mon',
            'local_time' => '10:00',
            'max_per_day' => 1,
            'mode' => 'review_only',
            'updated_by' => $user,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ([
            ['gf_content_review_events', 'approve'],
            ['gf_distribution_authorization_events', 'grant'],
        ] as [$table, $action]) {
            $db->insert($table, [
                'id' => Uuid::v7()->toRfc4122(),
                'organization_id' => $mine,
                'asset_id' => $mineAsset,
                'actor_id' => $user,
                'action' => $action,
                'created_at' => $now,
            ]);
        }
        $client->request('GET', '/api/admin/pilot/weekly-summary?week='.$thisWeek);
        self::assertResponseIsSuccessful();
        $authorized = $this->payload($client)['data']['mvp']['content'];
        self::assertTrue($authorized['eligibility_ready']);
        self::assertSame(1, $authorized['ready_to_schedule']);
        self::assertSame(1, $authorized['available_assets']);

        self::assertStringNotContainsString($other, (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('foreign.png', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        $client->request('GET', '/api/admin/pilot/weekly-summary.csv?week='.$thisWeek);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/csv', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $csv = (string) $client->getResponse()->getContent();
        self::assertCount(8, array_filter(explode("\r\n", $csv), static fn (string $line): bool => $line !== ''));
        self::assertStringStartsWith("date_utc,prepared_attempts,completed_reports,failed_attempts\r\n", $csv);
        self::assertStringContainsString($thisWeek.',1,1,0', $csv);
        self::assertStringNotContainsString('foreign.png', $csv);
        self::assertStringNotContainsString($other, $csv);

        // A later revocation immediately reduces *current* readiness, but does
        // not erase stored assets, future drafts or historic provider evidence.
        $db->insert('gf_distribution_authorization_events', [
            'id' => Uuid::v7()->toRfc4122(),
            'organization_id' => $mine,
            'asset_id' => $mineAsset,
            'actor_id' => $user,
            'action' => 'revoke',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $client->request('GET', '/api/admin/pilot/weekly-summary?week='.$thisWeek);
        self::assertResponseIsSuccessful();
        $revoked = $this->payload($client)['data']['mvp'];
        self::assertSame(0, $revoked['content']['ready_to_schedule']);
        self::assertSame(1, $revoked['content']['available_assets']);
        self::assertSame(10, $revoked['content']['used_bytes']);

        // Changing the week only filters legacy manual events; the operational
        // counters and evidence remain scoped by tenant, not by selected week.
        $client->request('GET', '/api/admin/pilot/weekly-summary?week='.$lastWeek);
        self::assertResponseIsSuccessful();
        $historic = $this->payload($client)['data'];
        self::assertSame(
            ['prepared_attempts' => 0, 'completed_reports' => 0, 'failed_attempts' => 1],
            $historic['totals'],
        );
        self::assertSame(0, $historic['mvp']['content']['ready_to_schedule']);
        self::assertSame(1, $historic['mvp']['upcoming']['total']);
        self::assertSame(1, $historic['mvp']['deliveries']['counts']['published']);

        $client->request('GET', '/api/admin/pilot/weekly-summary.csv?week=2026-02-30');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_pilot_week', $this->payload($client)['error']['code']);

        foreach ([
            '/api/admin/pilot/weekly-summary?week=2026-02-30',
            '/api/admin/pilot/weekly-summary?week='.$week->modify('+1 day')->format('Y-m-d'),
            '/api/admin/pilot/weekly-summary?week='.$week->modify('-12 weeks')->format('Y-m-d'),
            '/api/admin/pilot/weekly-summary?extra=1',
            '/api/admin/pilot/weekly-summary?week[]='.$thisWeek,
            '/api/admin/pilot/weekly-summary?week='.$thisWeek.'&organization_id='.$other,
        ] as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('invalid_pilot_week', $this->payload($client)['error']['code']);
        }

        // A selected workspace never grants access after its membership is revoked.
        $db->delete('gf_identity_memberships', ['user_id' => $user, 'organization_id' => $mine]);
        $client->request('GET', '/api/admin/pilot/weekly-summary?week='.$thisWeek);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('organization_access_changed', $this->payload($client)['error']['code']);
        $client->request('GET', '/api/admin/pilot/weekly-summary.csv?week='.$thisWeek);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('organization_required', $this->payload($client)['error']['code']);
    }

    /** @return array<string, mixed> */
    private function payload(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
