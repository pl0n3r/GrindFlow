<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use GrindFlow\Distribution\DistributionCommand;
use GrindFlow\Distribution\DistributionProviderException;
use GrindFlow\Distribution\ExternalPublicationLedger;
use GrindFlow\Kernel;
use GrindFlow\Scheduling\WeeklySlotCalculator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** Offline-only scheduling boundaries and synthetic delivery idempotence. */
final class MvpSchedulingStateTransitionsE2eTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testUtcBoundariesAndDstTransitionsAreDeterministic(): void
    {
        $calculator = new WeeklySlotCalculator();
        $rule = [
            'timezone' => 'UTC',
            'weekdays' => 'mon',
            'local_time' => '00:15',
            'max_per_day' => 2,
        ];
        $slots = $calculator->upcoming($rule, new DateTimeImmutable('2026-10-25T23:59:59Z'));
        self::assertCount(1, $slots);
        self::assertSame('2026-10-26T00:15:00Z', $slots[0]['scheduled_at_utc']);
        self::assertSame('2026-10-26', $slots[0]['local_date']);

        $rule['timezone'] = 'America/New_York';
        $rule['weekdays'] = 'sun';
        $rule['local_time'] = '02:30';
        // Spring-forward local 02:30 does not exist and must not become 03:30.
        self::assertSame([], $calculator->upcoming($rule, new DateTimeImmutable('2026-03-07T12:00:00Z')));

        $rule['local_time'] = '01:30';
        $autumn = $calculator->upcoming($rule, new DateTimeImmutable('2026-10-31T12:00:00Z'));
        self::assertCount(1, $autumn);
        self::assertSame('01:30', $autumn[0]['local_time']);
        // Existing PHP DateTime behavior chooses the first repeated occurrence.
        // This is a characterization, NOT a claim that ambiguity is rejected.
        self::assertSame('2026-11-01T05:30:00Z', $autumn[0]['scheduled_at_utc']);
        self::assertSame('America/New_York', $autumn[0]['timezone']);
    }

    public function testRejectedOrAmbiguousStateNeverTriggersDuplicateExternalDelivery(): void
    {
        static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $organizationId = Uuid::v7()->toRfc4122();
        $created = '2026-10-24 12:00:00';
        $db->insert('gf_identity_organizations', [
            'id' => $organizationId,
            'name' => 'Tenant sintético GF581',
            'slug' => 'gf581-'.substr($organizationId, 0, 20),
            'type' => 'independent',
            'created_at' => $created,
            'updated_at' => $created,
        ]);

        try {
            $ledger = new ExternalPublicationLedger(
                $db,
                static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-24T12:00:00Z', new DateTimeZone('UTC')),
            );
            foreach (['ambiguous', 'rejected'] as $kind) {
                $command = new DistributionCommand($organizationId, 'gf581-'.$kind, 'Mensaje de prueba sin PII');
                $sendCalls = 0;
                $firstSend = static function () use (&$sendCalls, $kind): never {
                    ++$sendCalls;
                    throw $kind === 'ambiguous'
                        ? DistributionProviderException::ambiguous()
                        : DistributionProviderException::rejected();
                };
                try {
                    $ledger->publish($command, 'facebook_page', 'page_id', '123456789', $firstSend);
                    self::fail('Expected first synthetic send failure.');
                } catch (DistributionProviderException $e) {
                    self::assertSame($kind, $e->kind);
                }
                $unexpectedSecondSend = static function () use (&$sendCalls): never {
                    ++$sendCalls;
                    self::fail('Unsafe replay must never invoke external delivery.');
                };
                try {
                    $ledger->publish($command, 'facebook_page', 'page_id', '123456789', $unexpectedSecondSend);
                    self::fail('Unsafe replay must remain blocked.');
                } catch (DistributionProviderException $e) {
                    self::assertSame($kind, $e->kind);
                    self::assertFalse($e->automaticRetryAllowed);
                }
                self::assertSame(1, $sendCalls);
                self::assertSame($kind, $db->fetchOne(
                    'SELECT status FROM gf_external_publication_attempts WHERE organization_id = :organization AND idempotency_key = :key',
                    ['organization' => $organizationId, 'key' => $command->idempotencyKey],
                ));
            }
        } finally {
            $db->delete('gf_external_publication_attempts', ['organization_id' => $organizationId]);
            $db->delete('gf_identity_organizations', ['id' => $organizationId]);
        }
    }
}
