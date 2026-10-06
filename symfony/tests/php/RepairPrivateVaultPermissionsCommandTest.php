<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Infrastructure\Storage\PrivateVaultDirectory;
use GrindFlow\Infrastructure\Storage\RepairPrivateVaultPermissionsCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RepairPrivateVaultPermissionsCommandTest extends TestCase
{
    public function testCommandTightensThenBecomesIdempotentWithoutExposingPathOrMode(): void
    {
        $base = sys_get_temp_dir().'/gf-vault-command-'.bin2hex(random_bytes(6));
        $project = $base.'/checkout/symfony';
        $external = $base.'/media';
        self::assertTrue(mkdir($project.'/public', 0700, true));
        self::assertTrue(mkdir($external, 0755));
        self::assertNotFalse(file_put_contents($external.'/sentinel.txt', 'same'));

        try {
            $tester = new CommandTester(new RepairPrivateVaultPermissionsCommand(
                new PrivateVaultDirectory($project, $external),
            ));
            self::assertSame(Command::SUCCESS, $tester->execute([]));
            self::assertSame(
                ['status' => 'ok', 'code' => 'tightened'],
                $this->payload($tester),
            );
            self::assertStringNotContainsString($external, $tester->getDisplay());
            self::assertStringNotContainsString('0700', $tester->getDisplay());
            self::assertSame('same', file_get_contents($external.'/sentinel.txt'));

            $again = new CommandTester(new RepairPrivateVaultPermissionsCommand(
                new PrivateVaultDirectory($project, $external),
            ));
            self::assertSame(Command::SUCCESS, $again->execute([]));
            self::assertSame(
                ['status' => 'ok', 'code' => 'already_private'],
                $this->payload($again),
            );
        } finally {
            @unlink($external.'/sentinel.txt');
            @rmdir($external);
            @rmdir($project.'/public');
            @rmdir($project);
            @rmdir($base.'/checkout');
            @rmdir($base);
        }
    }

    public function testCommandFailsClosedForMissingOrSymlinkedRoot(): void
    {
        $base = sys_get_temp_dir().'/gf-vault-command-fail-'.bin2hex(random_bytes(6));
        $project = $base.'/checkout/symfony';
        self::assertTrue(mkdir($project.'/public', 0700, true));
        self::assertTrue(mkdir($base.'/real', 0700));

        try {
            $missing = new CommandTester(new RepairPrivateVaultPermissionsCommand(
                new PrivateVaultDirectory($project, $base.'/missing'),
            ));
            self::assertSame(Command::FAILURE, $missing->execute([]));
            self::assertSame(
                ['status' => 'error', 'code' => 'missing'],
                $this->payload($missing),
            );

            self::assertTrue(symlink($base.'/real', $base.'/alias'));
            $symlink = new CommandTester(new RepairPrivateVaultPermissionsCommand(
                new PrivateVaultDirectory($project, $base.'/alias'),
            ));
            self::assertSame(Command::FAILURE, $symlink->execute([]));
            self::assertSame(
                ['status' => 'error', 'code' => 'root_unavailable'],
                $this->payload($symlink),
            );
        } finally {
            @unlink($base.'/alias');
            @rmdir($base.'/real');
            @rmdir($project.'/public');
            @rmdir($project);
            @rmdir($base.'/checkout');
            @rmdir($base);
        }
    }

    /** @return array{status:string,code:string} */
    private function payload(CommandTester $tester): array
    {
        $payload = json_decode(trim($tester->getDisplay()), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }
}
