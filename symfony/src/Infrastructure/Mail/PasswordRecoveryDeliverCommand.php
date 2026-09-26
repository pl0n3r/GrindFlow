<?php

declare(strict_types=1);

namespace GrindFlow\Infrastructure\Mail;

use Doctrine\DBAL\Connection;
use GrindFlow\Identity\Security\PasswordRecoveryNotifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'grindflow:password-recovery:deliver',
    description: 'Entrega correos de recuperación pendientes sin exponer tokens en la cola',
)]
final class PasswordRecoveryDeliverCommand extends Command
{
    private const CLAIM_SECONDS = 600;
    private const RETRY_SECONDS = 300;

    public function __construct(
        private readonly Connection $db,
        private readonly PasswordRecoveryNotifier $notifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Máximo de entregas por ejecución', '20');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 100],
        ]);
        if (!is_int($limit)) {
            $output->writeln('{"status":"error","code":"invalid_limit"}');

            return Command::INVALID;
        }

        $counts = ['claimed' => 0, 'delivered' => 0, 'failed' => 0, 'skipped' => 0];
        for ($i = 0; $i < $limit; ++$i) {
            $job = $this->claim();
            if ($job === null) {
                break;
            }
            ++$counts['claimed'];
            ++$counts[$this->deliver($job)];
        }

        $output->writeln(json_encode(
            ['status' => 'ok'] + $counts,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ));

        return $counts['failed'] === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /** @return array{id:string,user_id:string}|null */
    private function claim(): ?array
    {
        $now = gmdate('Y-m-d H:i:s');
        $stale = gmdate('Y-m-d H:i:s', time() - self::CLAIM_SECONDS);

        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $row = $this->db->fetchAssociative(
                <<<'SQL'
                    SELECT id, user_id
                    FROM gf_password_recovery_outbox
                    WHERE kind = :kind
                      AND delivered_at IS NULL
                      AND available_at <= :now
                      AND (claimed_at IS NULL OR claimed_at < :stale)
                    ORDER BY created_at, id
                    LIMIT 1
                    SQL,
                ['kind' => 'reset', 'now' => $now, 'stale' => $stale],
            );
            if ($row === false) {
                return null;
            }

            $claimed = $this->db->executeStatement(
                <<<'SQL'
                    UPDATE gf_password_recovery_outbox
                    SET claimed_at = :now, attempts = attempts + 1, updated_at = :now
                    WHERE id = :id
                      AND delivered_at IS NULL
                      AND (claimed_at IS NULL OR claimed_at < :stale)
                    SQL,
                ['now' => $now, 'id' => (string) $row['id'], 'stale' => $stale],
            );
            if ($claimed === 1) {
                return ['id' => (string) $row['id'], 'user_id' => (string) $row['user_id']];
            }
        }

        return null;
    }

    /** @param array{id:string,user_id:string} $job */
    private function deliver(array $job): string
    {
        $account = $this->db->fetchAssociative(
            'SELECT email, name FROM gf_identity_users WHERE id = :id AND is_active = 1 LIMIT 1',
            ['id' => $job['user_id']],
        );
        if ($account === false) {
            $this->db->delete('gf_password_recovery_outbox', ['id' => $job['id']]);

            return 'skipped';
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $tokenHash = hash('sha256', $token);
        $now = gmdate('Y-m-d H:i:s');
        $expires = gmdate('Y-m-d H:i:s', time() + 3600);

        $this->db->transactional(function (Connection $db) use ($job, $tokenHash, $now, $expires): void {
            // The job may have been superseded after it was claimed.
            if ($db->fetchOne(
                'SELECT id FROM gf_password_recovery_outbox WHERE id = :id AND delivered_at IS NULL',
                ['id' => $job['id']],
            ) === false) {
                throw new \RuntimeException('delivery_superseded');
            }
            $db->delete('gf_password_reset_tokens', ['user_id' => $job['user_id']]);
            $db->insert('gf_password_reset_tokens', [
                'user_id' => $job['user_id'],
                'token_hash' => $tokenHash,
                'expires_at' => $expires,
                'created_at' => $now,
            ]);
        });

        try {
            $delivered = $this->notifier->sendReset(
                (string) $account['email'],
                (string) $account['name'],
                $token,
            );
        } catch (\Throwable) {
            $delivered = false;
        }

        if ($delivered) {
            $this->db->update('gf_password_recovery_outbox', [
                'delivered_at' => gmdate('Y-m-d H:i:s'),
                'claimed_at' => null,
                'last_error_code' => null,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ], ['id' => $job['id']]);

            return 'delivered';
        }

        $this->db->transactional(function (Connection $db) use ($job, $tokenHash): void {
            // Compare-and-delete: a newer request/worker may already have
            // replaced the user's token. Never delete that newer hash.
            $db->delete('gf_password_reset_tokens', [
                'user_id' => $job['user_id'],
                'token_hash' => $tokenHash,
            ]);
            $db->update('gf_password_recovery_outbox', [
                'claimed_at' => null,
                'available_at' => gmdate('Y-m-d H:i:s', time() + self::RETRY_SECONDS),
                'last_error_code' => 'delivery_failed',
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ], ['id' => $job['id'], 'delivered_at' => null]);
        });

        return 'failed';
    }
}
