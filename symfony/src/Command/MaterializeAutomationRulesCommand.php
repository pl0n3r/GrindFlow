<?php

declare(strict_types=1);

namespace GrindFlow\Command;

use GrindFlow\Scheduling\PublishingAutomationRuleScheduler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** No cron/worker installed; explicit dry-run by default. */
#[AsCommand(name: 'grindflow:scheduling:materialize', description: 'Prepara borradores locales para una organización, sin publicar')]
final class MaterializeAutomationRulesCommand extends Command
{
    public function __construct(private readonly PublishingAutomationRuleScheduler $scheduler)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('organization', null, InputOption::VALUE_REQUIRED, 'UUID de organización autorizada')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Permitir la reserva local de borradores; NO publica fuera de GrindFlow');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getOption('organization');
        if (!is_string($id) || preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/iD', $id) !== 1) {
            $output->writeln('{"status":"invalid_organization"}');
            return Command::INVALID;
        }
        try {
            $result = $this->scheduler->materialize($id, $input->getOption('apply') === true);
        } catch (\Throwable) {
            // Do not leak source paths, account identifiers or SQL details.
            $output->writeln('{"status":"unavailable"}');
            return Command::FAILURE;
        }
        $output->writeln(json_encode($result, JSON_THROW_ON_ERROR));
        return in_array($result['status'], ['dry_run', 'paused', 'no_future_slots', 'prepared'], true)
            ? Command::SUCCESS : Command::FAILURE;
    }
}
