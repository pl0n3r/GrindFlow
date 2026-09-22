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
                'scheduled_at_utc' => $week->format('Y-m-d 10:00:00'),
                'timezone' => 'UTC',
                'local_date' => $thisWeek,
                'local_time' => '10:00',
                'status' => 'draft',
                'created_at' => $now,
            ]);
        }

        foreach ([
            [$mine, $mineDraft, 'prepare', $thisWeek.' 10:00:00'],
            [$mine, $mineDraft, 'complete', $thisWeek.' 11:00:00'],
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
        self::assertStringNotContainsString($other, (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('foreign.png', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        $client->request('GET', '/api/admin/pilot/weekly-summary?week='.$lastWeek);
        self::assertResponseIsSuccessful();
        self::assertSame(
            ['prepared_attempts' => 0, 'completed_reports' => 0, 'failed_attempts' => 1],
            $this->payload($client)['data']['totals'],
        );

        foreach ([
            '/api/admin/pilot/weekly-summary?week=2026-02-30',
            '/api/admin/pilot/weekly-summary?week='.$week->modify('+1 day')->format('Y-m-d'),
            '/api/admin/pilot/weekly-summary?week='.$week->modify('-12 weeks')->format('Y-m-d'),
            '/api/admin/pilot/weekly-summary?extra=1',
        ] as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('invalid_pilot_week', $this->payload($client)['error']['code']);
        }
    }

    /** @return array<string, mixed> */
    private function payload(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
