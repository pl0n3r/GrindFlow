<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Distribution\DistributionCommand;
use GrindFlow\Distribution\ExternalPublicationLedger;
use GrindFlow\Kernel;
use GrindFlow\Ops\Security\EmergencyStopStore;
use GrindFlow\Scheduling\PublishingAutomationRuleScheduler;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * SQL-backed, synthetic-only regression. Outer transaction rolls back STOP,
 * audit and revocation side effects, never touching a real user or network.
 */
#[Group('emergency-stop')]
final class EmergencyStopRuntimeTest extends KernelTestCase
{
    private Connection $db;
    private EmergencyStopStore $stop;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        $this->db->beginTransaction();
        $this->stop = new EmergencyStopStore($this->db);
        $this->db->executeStatement("DELETE FROM gf_emergency_stop WHERE id = 1");
    }

    protected function tearDown(): void
    {
        if ($this->db->getTransactionNestingLevel() > 0) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function initialRunning(): void
    {
        $this->db->insert('gf_emergency_stop', [
            'id' => 1, 'state' => 'RUNNING', 'revision' => 100,
            'revocation_complete' => 1, 'actor' => 'synthetic-admin',
            'reason' => 'ci_fixture', 'changed_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function test_stop_denies_both_distribution_paths_and_scheduler(): void
    {
        $tenant = Uuid::v7()->toRfc4122();
        $sendCount = 0;
        $command = new DistributionCommand($tenant, 'synthetic-stop', 'No external delivery');
        foreach (['facebook_page' => 'page_id', 'instagram_reels' => 'instagram_user_id'] as $provider => $key) {
            try {
                (new ExternalPublicationLedger($this->db))->publish(
                    $command, $provider, $key, '123456789',
                    function () use (&$sendCount) { ++$sendCount; throw new \LogicException('network_called'); }
                );
                self::fail('Absent STOP state must fail closed');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('emergency_stop', $e->getMessage());
            }
        }
        self::assertSame(0, $sendCount);
        $scheduler = static::getContainer()->get(PublishingAutomationRuleScheduler::class);
        self::assertSame('emergency_stop', $scheduler->materialize($tenant, true)['status']);
    }

    public function test_admin_only_resume_and_audited_stop(): void
    {
        $this->initialRunning();
        $revision = $this->stop->stop('synthetic-admin', 'incident_detected');
        self::assertSame(101, $revision);
        self::assertSame('STOP', $this->db->fetchOne('SELECT state FROM gf_emergency_stop WHERE id = 1'));
        self::assertSame(1, (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM gf_emergency_stop_audit WHERE state = 'STOP' AND revision = 101"
        ));
        $this->stop->finishRevocation();
        self::assertSame(102, $this->stop->resume('synthetic-admin', 'explicit_recovery'));
        self::assertSame(102, $this->stop->runningRevision());
    }

    public function test_partial_token_session_revocation_remains_stopped(): void
    {
        $this->initialRunning();
        $revision = $this->stop->stop('synthetic-admin', 'partial_failure');
        self::assertSame($revision, $this->stop->stop('synthetic-admin', 'repeated'));
        try {
            $this->stop->resume('synthetic-admin', 'premature');
            self::fail('Partial revocation must veto resume');
        } catch (\RuntimeException $error) {
            self::assertSame('emergency_stop_resume_denied', $error->getMessage());
        }
        self::assertSame('STOP', $this->db->fetchOne('SELECT state FROM gf_emergency_stop WHERE id = 1'));
        $this->stop->finishRevocation();
        $this->stop->finishRevocation();
        self::assertSame(1, (int) $this->db->fetchOne(
            'SELECT revocation_complete FROM gf_emergency_stop WHERE id = 1'
        ));
    }

    public function test_absent_or_stale_stop_state_fails_closed(): void
    {
        try {
            $this->stop->assertRunning();
            self::fail('Absence is not RUNNING');
        } catch (\RuntimeException $error) {
            self::assertSame('emergency_stop', $error->getMessage());
        }
        $this->initialRunning();
        self::assertSame(100, $this->stop->assertRunning(100));
        try {
            $this->stop->assertRunning(99);
            self::fail('Stale fencing revision must fail');
        } catch (\RuntimeException $error) {
            self::assertSame('emergency_stop_stale_revision', $error->getMessage());
        }
    }

    public function test_two_tenants_and_no_live_network_access(): void
    {
        $tenants = [Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122()];
        self::assertNotSame($tenants[0], $tenants[1]);
        foreach ($tenants as $tenant) {
            $outbound = 0;
            try {
                (new ExternalPublicationLedger($this->db))->publish(
                    new DistributionCommand($tenant, 'stop-'.$tenant, 'Fixture only'),
                    'instagram_reels', 'instagram_user_id', '12345678',
                    function () use (&$outbound) { ++$outbound; throw new \LogicException('network'); }
                );
                self::fail('Tenant must be denied');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('emergency_stop', $e->getMessage());
            }
            self::assertSame(0, $outbound);
        }
    }
}
