<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * This suite only runs after Doctrine migrations on disposable Symfony MariaDB.
 * It never addresses existing Laravel accounts or production credentials.
 */
final class IdentitySchemaTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testIdentityMappingAndMigratedTablesExist(): void
    {
        self::bootKernel();

        $connection = static::getContainer()->get(Connection::class);
        $tables = $connection->createSchemaManager()->listTableNames();

        self::assertContains('gf_identity_users', $tables);
        self::assertContains('gf_identity_organizations', $tables);
        self::assertContains('gf_identity_memberships', $tables);

        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertCount(3, $entityManager->getMetadataFactory()->getAllMetadata());
    }

    public function testMembershipCannotReferenceForeignIdentityOrDuplicateAssignment(): void
    {
        self::bootKernel();

        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $userId = Uuid::v7()->toRfc4122();
        $alphaId = Uuid::v7()->toRfc4122();
        $betaId = Uuid::v7()->toRfc4122();
        $at = gmdate('Y-m-d H:i:s');

        $db->beginTransaction();

        try {
            $db->insert('gf_identity_users', [
                'id' => $userId,
                'name' => 'Synthetic creator',
                'email' => $userId.'@example.test',
                'password_hash' => '$2y$12$synthetic-fixture-not-a-real-password',
                'platform_role' => 'model',
                'is_active' => 1,
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            foreach ([$alphaId => 'alpha-', $betaId => 'beta-'] as $organizationId => $slug) {
                $db->insert('gf_identity_organizations', [
                    'id' => $organizationId,
                    'name' => 'Synthetic workspace',
                    'slug' => $slug.$organizationId,
                    'type' => 'independent',
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
            }

            $membership = [
                'id' => Uuid::v7()->toRfc4122(),
                'user_id' => $userId,
                'organization_id' => $alphaId,
                'role' => 'model',
                'created_at' => $at,
                'updated_at' => $at,
            ];
            $db->insert('gf_identity_memberships', $membership);

            $visible = $db->fetchFirstColumn(
                'SELECT organization_id FROM gf_identity_memberships WHERE user_id = ? AND organization_id = ?',
                [$userId, $alphaId],
            );

            self::assertSame([$alphaId], $visible);
            self::assertSame(0, (int) $db->fetchOne(
                'SELECT COUNT(*) FROM gf_identity_memberships WHERE user_id = ? AND organization_id = ?',
                [$userId, $betaId],
            ));

            try {
                $db->insert('gf_identity_memberships', [
                    ...$membership,
                    'id' => Uuid::v7()->toRfc4122(),
                ]);
                self::fail('Duplicate tenant membership was accepted.');
            } catch (UniqueConstraintViolationException) {
                // MariaDB must enforce uniqueness even without an ORM call.
            }

            try {
                $db->insert('gf_identity_memberships', [
                    ...$membership,
                    'id' => Uuid::v7()->toRfc4122(),
                    'user_id' => Uuid::v7()->toRfc4122(),
                ]);
                self::fail('Foreign user ID was accepted.');
            } catch (ForeignKeyConstraintViolationException) {
                // Membership actor cannot point at a non-existing account.
            }
        } finally {
            $db->rollBack();
        }
    }
}
