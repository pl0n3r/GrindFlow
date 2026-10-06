<?php

declare(strict_types=1);

namespace GrindFlow\Identity\Application;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use GrindFlow\Identity\Entity\IdentityUser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'grindflow:s4:provision-smoke-identity',
    description: 'Reconcilia exclusivamente la identidad sintética reservada del smoke S4',
)]
final class ProvisionSmokeIdentityCommand extends Command
{
    private const EMAIL = 'e2e-oidc-smoke@grindflow.test';
    private const DISPLAY_NAME = 'S4 Synthetic Smoke';
    private const ORGANIZATION_NAME = 'S4 Synthetic Smoke';
    private const ORGANIZATION_SLUG = 'e2e-oidc-smoke';
    private const ROLE = 'model';
    private const SECRET_ENV = 'GRINDFLOW_S4_SMOKE_PASSWORD';
    private const USER_EMAIL_UNIQUE_CONSTRAINT = 'uq_gf_identity_users_email';
    private const TABLES = [
        'gf_identity_users',
        'gf_identity_organizations',
        'gf_identity_memberships',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly PasswordHasherFactoryInterface $hashers,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $secret = $this->secret();
        if ($secret === '') {
            return $this->finish($output, 'secret_missing', false);
        }

        try {
            if (!$this->db->createSchemaManager()->tablesExist(self::TABLES)) {
                return $this->finish($output, 'schema_missing', false);
            }

            $code = $this->reconcileTransaction($secret);
        } catch (\Throwable) {
            return $this->finish($output, 'transaction_failed', false);
        }

        return $this->finish(
            $output,
            $code,
            in_array($code, ['created', 'already_ready', 'rotated'], true),
        );
    }

    private function secret(): string
    {
        $value = $_SERVER[self::SECRET_ENV]
            ?? $_ENV[self::SECRET_ENV]
            ?? getenv(self::SECRET_ENV)
            ?: '';

        return is_string($value) ? trim($value) : '';
    }

    private function reconcileTransaction(string $secret): string
    {
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                return $this->db->transactional(
                    fn (Connection $db): string => $this->reconcile($db, $secret),
                );
            } catch (UniqueConstraintViolationException $exception) {
                if (
                    $attempt !== 0
                    || !str_contains($exception->getMessage(), self::USER_EMAIL_UNIQUE_CONSTRAINT)
                ) {
                    throw $exception;
                }
            }
        }

        throw new \LogicException('Unreachable reconciliation retry state.');
    }

    private function reconcile(Connection $db, string $secret): string
    {
        $user = $db->fetchAssociative(
            <<<'SQL'
                SELECT id, name, password_hash, platform_role, is_active
                FROM gf_identity_users
                WHERE email = :email
                LIMIT 1
                SQL,
            ['email' => self::EMAIL],
        );
        $organization = $db->fetchAssociative(
            <<<'SQL'
                SELECT id, name, type
                FROM gf_identity_organizations
                WHERE slug = :slug
                LIMIT 1
                SQL,
            ['slug' => self::ORGANIZATION_SLUG],
        );

        if (($user === false) !== ($organization === false)) {
            return 'identity_conflict';
        }

        if ($user !== false && $organization !== false) {
            if (!$this->existingStateHasValidStructure($db, $user, $organization)) {
                return 'identity_conflict';
            }

            $hasher = $this->hashers->getPasswordHasher(IdentityUser::class);
            $hash = (string) ($user['password_hash'] ?? '');
            if ($hasher->verify($hash, $secret)) {
                return 'already_ready';
            }

            $affected = $db->update(
                'gf_identity_users',
                ['password_hash' => $hasher->hash($secret)],
                [
                    'id' => (string) $user['id'],
                    'email' => self::EMAIL,
                ],
            );
            if ($affected !== 1) {
                return 'identity_conflict';
            }

            return 'rotated';
        }

        $hasher = $this->hashers->getPasswordHasher(IdentityUser::class);
        $now = gmdate('Y-m-d H:i:s');
        $userId = Uuid::v7()->toRfc4122();
        $organizationId = Uuid::v7()->toRfc4122();

        $db->insert('gf_identity_users', [
            'id' => $userId,
            'name' => self::DISPLAY_NAME,
            'email' => self::EMAIL,
            'password_hash' => $hasher->hash($secret),
            'platform_role' => self::ROLE,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $db->insert('gf_identity_organizations', [
            'id' => $organizationId,
            'name' => self::ORGANIZATION_NAME,
            'slug' => self::ORGANIZATION_SLUG,
            'type' => 'independent',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $db->insert('gf_identity_memberships', [
            'id' => Uuid::v7()->toRfc4122(),
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'role' => self::ROLE,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return 'created';
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $organization
     */
    private function existingStateHasValidStructure(
        Connection $db,
        array $user,
        array $organization,
    ): bool {
        if (
            (string) ($user['name'] ?? '') !== self::DISPLAY_NAME
            || (string) ($user['platform_role'] ?? '') !== self::ROLE
            || (int) ($user['is_active'] ?? 0) !== 1
            || (string) ($organization['name'] ?? '') !== self::ORGANIZATION_NAME
            || (string) ($organization['type'] ?? '') !== 'independent'
            || (string) ($user['password_hash'] ?? '') === ''
        ) {
            return false;
        }

        $memberships = $db->fetchAllAssociative(
            <<<'SQL'
                SELECT user_id, organization_id, role
                FROM gf_identity_memberships
                WHERE user_id = :user_id OR organization_id = :organization_id
                ORDER BY id
                SQL,
            [
                'user_id' => (string) $user['id'],
                'organization_id' => (string) $organization['id'],
            ],
        );
        if (count($memberships) !== 1) {
            return false;
        }

        $membership = $memberships[0];

        return (string) ($membership['user_id'] ?? '') === (string) $user['id']
            && (string) ($membership['organization_id'] ?? '') === (string) $organization['id']
            && (string) ($membership['role'] ?? '') === self::ROLE;
    }

    private function finish(OutputInterface $output, string $code, bool $ok): int
    {
        $output->writeln(json_encode(
            ['status' => $ok ? 'ok' : 'error', 'code' => $code],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }
}
