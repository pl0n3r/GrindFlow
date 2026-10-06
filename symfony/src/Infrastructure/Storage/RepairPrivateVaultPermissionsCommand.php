<?php

declare(strict_types=1);

namespace GrindFlow\Infrastructure\Storage;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'grindflow:s4:repair-private-vault-permissions',
    description: 'Endurece únicamente los permisos del Private Vault externo ya configurado',
)]
final class RepairPrivateVaultPermissionsCommand extends Command
{
    private const SUCCESS_CODES = ['tightened', 'already_private'];

    public function __construct(
        private readonly PrivateVaultDirectory $vault,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $code = $this->vault->tightenPrivatePermissions();
        $ok = in_array($code, self::SUCCESS_CODES, true);

        $output->writeln(json_encode(
            ['status' => $ok ? 'ok' : 'error', 'code' => $code],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }
}
