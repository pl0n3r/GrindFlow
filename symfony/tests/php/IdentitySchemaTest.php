<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Runs only in a disposable S1 MariaDB database migrated in CI.
 */
final class IdentitySchemaTest extends KernelTestCase
{
    private Connection $db;
    protected function setUp(): void
    {
        static::bootKernel();
        $connection = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->db = $connection;
    }

    protected function tearDown(): void
    {
        $this->db->executeStatement('DELETE FROM gf_memberships');
        $this->db->executeStatement('DELETE FROM gf_organizations');
        $this->db->executeStatement('DELETE FROM gf_users');
        parent::tearDown();
    }

    public function testUniqueMembershipAndForeignTenantAreEnforcedByMariaDb(): void
    {
        $user = Uuid::v4()->toRfc4122();
        $organization = Uuid::v4()->toRfc4122();
        $membership = Uuid::v4()->toRfc4122();
        $now = '2026-09-20 00:00:00.000000';

        $this->db->insert('gf_users', [
            'id' => $user,
            'name' => 'Test account',
            'email' => 'schema-test@example.invalid',
            'password_hash' => 'synthetic-hash-no-login',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->db->insert('gf_organizations', [
            'id' => $organization,
            'name' => 'Test workspace',
            'slug' => 'schema-test',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->db->insert('gf_memberships', [
            'id' => $membership,
            'user_id' => $user,
            'organization_id' => $organization,
            'role' => 'studio',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::assertSame(1, (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM gf_memberships WHERE user_id = ? AND organization_id = ?',
            [$user, $organization],
        ));

        try {
            $this->db->executeStatement(
                'UPDATE gf_memberships SET organization_id = ? WHERE id = ?',
                [Uuid::v4()->toRfc4122(), $membership],
            );
            self::fail('Membership identity must stay immutable');
        } catch (\Doctrine\DBAL\Exception $exception) {
            self::assertStringContainsString('membership identity is immutable', $exception->getMessage());
        }

        try {
            $this->db->insert('gf_memberships', [
                'id' => Uuid::v4()->toRfc4122(),
                'user_id' => $user,
                'organization_id' => $organization,
                'role' => 'editor',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            self::fail('Duplicate membership must be rejected');
        } catch (\Doctrine\DBAL\Exception $exception) {
            self::assertNotSame('', $exception->getMessage());
        }

        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM gf_memberships'));
    }
}
