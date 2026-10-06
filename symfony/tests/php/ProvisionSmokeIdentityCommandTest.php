<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use GrindFlow\Identity\Application\ProvisionSmokeIdentityCommand;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

final class ProvisionSmokeIdentityCommandTest extends KernelTestCase
{
    private const EMAIL = 'e2e-oidc-smoke@grindflow.test';
    private const SLUG = 'e2e-oidc-smoke';
    private const SECRET = 'synthetic-s4-smoke-password-2026';
    private const FOREIGN_EMAIL = 'e2e-oidc-foreign@grindflow.test';
    private const FOREIGN_USER_ID = '00000000-0000-7000-8000-000000000342';
    private const FOREIGN_MEMBERSHIP_ID = '00000000-0000-7000-8000-000000000343';

    private Connection $db;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $db = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
        $this->clearReservedState();
        $this->setSecret(self::SECRET);
    }

    protected function tearDown(): void
    {
        $this->clearReservedState();
        $this->clearSecret();
        parent::tearDown();
    }

    public function testCreatesReservedUserOrganizationAndMembershipWhenSchemaIsReady(): void
    {
        $application = new Application(static::$kernel);
        $command = $application->find('grindflow:s4:provision-smoke-identity');
        self::assertSame(
            'grindflow:s4:provision-smoke-identity',
            $command->getName(),
        );

        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(
            ['status' => 'ok', 'code' => 'created'],
            json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR),
        );

        $user = $this->db->fetchAssociative(
            'SELECT id, password_hash, platform_role, is_active FROM gf_identity_users WHERE email = ?',
            [self::EMAIL],
        );
        $organization = $this->db->fetchAssociative(
            'SELECT id, type FROM gf_identity_organizations WHERE slug = ?',
            [self::SLUG],
        );
        self::assertIsArray($user);
        self::assertIsArray($organization);
        self::assertSame('model', $user['platform_role']);
        self::assertSame(1, (int) $user['is_active']);
        self::assertSame('independent', $organization['type']);
        self::assertTrue(password_verify(self::SECRET, (string) $user['password_hash']));

        $membership = $this->db->fetchAssociative(
            'SELECT user_id, organization_id, role FROM gf_identity_memberships WHERE user_id = ? AND organization_id = ?',
            [(string) $user['id'], (string) $organization['id']],
        );
        self::assertIsArray($membership);
        self::assertSame('model', $membership['role']);
    }

    public function testSecondExecutionIsIdempotentWithoutDuplicateOrPrivilegeEscalation(): void
    {
        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $before = $this->snapshot();

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(
            ['status' => 'ok', 'code' => 'already_ready'],
            json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertSame($before, $this->snapshot());
        self::assertSame(1, (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM gf_identity_memberships WHERE user_id = ? AND organization_id = ?',
            [$before['user_id'], $before['organization_id']],
        ));
        self::assertSame('model', $before['platform_role']);
        self::assertSame('model', $before['membership_role']);
    }

    public function testMissingSecretIncompleteSchemaOrConflictFailsClosedWithoutPartialMutation(): void
    {
        $this->clearSecret();
        $missing = $this->tester();
        self::assertSame(Command::FAILURE, $missing->execute([]));
        self::assertSame('secret_missing', $this->payload($missing)['code']);
        self::assertSame([0, 0, 0], $this->counts());

        $this->setSecret(self::SECRET);
        $schema = $this->createMock(AbstractSchemaManager::class);
        $schema->method('tablesExist')->willReturn(false);
        $db = $this->createMock(Connection::class);
        $db->method('createSchemaManager')->willReturn($schema);
        $hashers = $this->createMock(PasswordHasherFactoryInterface::class);
        $schemaMissing = new CommandTester(
            new ProvisionSmokeIdentityCommand($db, $hashers),
        );
        self::assertSame(Command::FAILURE, $schemaMissing->execute([]));
        self::assertSame('schema_missing', $this->payload($schemaMissing)['code']);

        $now = gmdate('Y-m-d H:i:s');
        $this->db->insert('gf_identity_users', [
            'id' => '00000000-0000-7000-8000-000000000341',
            'name' => 'Partial smoke identity',
            'email' => self::EMAIL,
            'password_hash' => password_hash(self::SECRET, PASSWORD_BCRYPT),
            'platform_role' => 'model',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $conflict = $this->tester();
        self::assertSame(Command::FAILURE, $conflict->execute([]));
        self::assertSame('identity_conflict', $this->payload($conflict)['code']);
        self::assertSame([1, 0, 0], $this->counts());

        $this->clearReservedState();
        $this->setSecret(self::SECRET);
        $created = $this->tester();
        self::assertSame(Command::SUCCESS, $created->execute([]));
        $before = $this->snapshot();

        $this->setSecret('different-synthetic-s4-smoke-password-2026');
        $wrongSecret = $this->tester();
        self::assertSame(Command::FAILURE, $wrongSecret->execute([]));
        self::assertSame('identity_conflict', $this->payload($wrongSecret)['code']);
        self::assertSame($before, $this->snapshot());

        $this->setSecret(self::SECRET);
        $this->db->insert('gf_identity_users', [
            'id' => self::FOREIGN_USER_ID,
            'name' => 'Foreign synthetic actor',
            'email' => self::FOREIGN_EMAIL,
            'password_hash' => password_hash('foreign-synthetic-password-2026', PASSWORD_BCRYPT),
            'platform_role' => 'model',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->db->insert('gf_identity_memberships', [
            'id' => self::FOREIGN_MEMBERSHIP_ID,
            'user_id' => self::FOREIGN_USER_ID,
            'organization_id' => $before['organization_id'],
            'role' => 'model',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $foreignMembership = $this->tester();
        self::assertSame(Command::FAILURE, $foreignMembership->execute([]));
        self::assertSame('identity_conflict', $this->payload($foreignMembership)['code']);
        self::assertSame($before, $this->snapshot());
        self::assertSame(2, (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM gf_identity_memberships WHERE organization_id = ?',
            [$before['organization_id']],
        ));
    }

    public function testOutputIsAllowlistedAndNeverExposesPasswordHashDsnOrIdentifiers(): void
    {
        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $payload = $this->payload($tester);
        self::assertSame(['status', 'code'], array_keys($payload));
        self::assertContains($payload['code'], [
            'created',
            'already_ready',
            'secret_missing',
            'schema_missing',
            'identity_conflict',
            'transaction_failed',
        ]);

        $display = $tester->getDisplay();
        foreach ([self::SECRET, self::EMAIL, self::SLUG, 'password_hash', 'DATABASE_URL', 'mysql://'] as $private) {
            self::assertStringNotContainsString($private, $display);
        }
        self::assertDoesNotMatchRegularExpression(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            $display,
        );
    }

    private function tester(): CommandTester
    {
        $application = new Application(static::$kernel);

        return new CommandTester(
            $application->find('grindflow:s4:provision-smoke-identity'),
        );
    }

    /** @return array<string, mixed> */
    private function payload(CommandTester $tester): array
    {
        return json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array{user_id:string,organization_id:string,platform_role:string,membership_role:string,password_hash:string} */
    private function snapshot(): array
    {
        $row = $this->db->fetchAssociative(
            <<<'SQL'
                SELECT actor.id AS user_id,
                       organization.id AS organization_id,
                       actor.platform_role,
                       membership.role AS membership_role,
                       actor.password_hash
                FROM gf_identity_users actor
                INNER JOIN gf_identity_memberships membership ON membership.user_id = actor.id
                INNER JOIN gf_identity_organizations organization ON organization.id = membership.organization_id
                WHERE actor.email = :email AND organization.slug = :slug
                SQL,
            ['email' => self::EMAIL, 'slug' => self::SLUG],
        );
        self::assertIsArray($row);

        return array_map(static fn (mixed $value): string => (string) $value, $row);
    }

    /** @return array{int,int,int} */
    private function counts(): array
    {
        return [
            (int) $this->db->fetchOne('SELECT COUNT(*) FROM gf_identity_users WHERE email = ?', [self::EMAIL]),
            (int) $this->db->fetchOne('SELECT COUNT(*) FROM gf_identity_organizations WHERE slug = ?', [self::SLUG]),
            (int) $this->db->fetchOne(
                <<<'SQL'
                    SELECT COUNT(*)
                    FROM gf_identity_memberships membership
                    LEFT JOIN gf_identity_users actor ON actor.id = membership.user_id
                    LEFT JOIN gf_identity_organizations organization ON organization.id = membership.organization_id
                    WHERE actor.email = :email OR organization.slug = :slug
                    SQL,
                ['email' => self::EMAIL, 'slug' => self::SLUG],
            ),
        ];
    }

    private function clearReservedState(): void
    {
        $userId = $this->db->fetchOne(
            'SELECT id FROM gf_identity_users WHERE email = ?',
            [self::EMAIL],
        );
        $organizationId = $this->db->fetchOne(
            'SELECT id FROM gf_identity_organizations WHERE slug = ?',
            [self::SLUG],
        );
        if (is_string($userId)) {
            $this->db->delete('gf_identity_memberships', ['user_id' => $userId]);
        }
        if (is_string($organizationId)) {
            $this->db->delete('gf_identity_memberships', ['organization_id' => $organizationId]);
        }
        $this->db->delete('gf_identity_memberships', ['user_id' => self::FOREIGN_USER_ID]);
        $this->db->delete('gf_identity_users', ['email' => self::EMAIL]);
        $this->db->delete('gf_identity_organizations', ['slug' => self::SLUG]);
        $this->db->delete('gf_identity_users', ['email' => self::FOREIGN_EMAIL]);
    }

    private function setSecret(string $secret): void
    {
        putenv('GRINDFLOW_S4_SMOKE_PASSWORD='.$secret);
        $_ENV['GRINDFLOW_S4_SMOKE_PASSWORD'] = $secret;
        $_SERVER['GRINDFLOW_S4_SMOKE_PASSWORD'] = $secret;
    }

    private function clearSecret(): void
    {
        putenv('GRINDFLOW_S4_SMOKE_PASSWORD');
        unset(
            $_ENV['GRINDFLOW_S4_SMOKE_PASSWORD'],
            $_SERVER['GRINDFLOW_S4_SMOKE_PASSWORD'],
        );
    }
}
