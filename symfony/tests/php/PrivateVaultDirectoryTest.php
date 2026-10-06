<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Infrastructure\Storage\PrivateVaultDirectory;
use PHPUnit\Framework\TestCase;

final class PrivateVaultDirectoryTest extends TestCase
{
    public function testDefaultRootAndExplicitExternalSiblingRemainPrivate(): void
    {
        $base = sys_get_temp_dir().'/gf-vault-root-'.bin2hex(random_bytes(6));
        $project = $base.'/checkout/symfony';
        self::assertTrue(mkdir($project.'/public', 0700, true));
        try {
            $default = new PrivateVaultDirectory($project);
            self::assertSame($project.'/var/vault', $default->root());
            self::assertSame($project.'/var/vault', $default->ensureWritable());
            self::assertDirectoryExists($project.'/var/vault');

            $external = new PrivateVaultDirectory($project, $base.'/media');
            self::assertSame($base.'/media', $external->ensureWritable());
            self::assertDirectoryExists($base.'/media');
            self::assertSame($base.'/media', $external->root());
            self::assertNotSame($default->root(), $external->root());

            self::assertTrue(chmod($base.'/media', 0755));
            try {
                $external->ensureWritable();
                self::fail('An externally readable Vault directory was accepted.');
            } catch (\RuntimeException) {
                // The application must not use an operator-owned public directory.
            }
            self::assertTrue(chmod($base.'/media', 0700));
            self::assertSame($base.'/media', $external->ensureWritable());
        } finally {
            @rmdir($base.'/media');
            @rmdir($project.'/var/vault');
            @rmdir($project.'/var');
            @rmdir($project.'/public');
            @rmdir($project);
            @rmdir($base.'/checkout');
            @rmdir($base);
        }
    }

    public function testTightenPrivatePermissionsOnlyNarrowsExistingExternalRoot(): void
    {
        $base = sys_get_temp_dir().'/gf-vault-permissions-'.bin2hex(random_bytes(6));
        $project = $base.'/checkout/symfony';
        $external = $base.'/media';
        self::assertTrue(mkdir($project.'/public', 0700, true));
        self::assertTrue(mkdir($external, 0755));
        self::assertNotFalse(file_put_contents($external.'/sentinel.txt', 'unchanged'));

        try {
            $vault = new PrivateVaultDirectory($project, $external);
            self::assertSame('tightened', $vault->tightenPrivatePermissions());
            clearstatcache(true, $external);
            self::assertSame(0, ((int) fileperms($external)) & 0077);
            self::assertSame('unchanged', file_get_contents($external.'/sentinel.txt'));
            self::assertSame('already_private', $vault->tightenPrivatePermissions());

            $defaultRoot = $project.'/var/vault';
            self::assertTrue(mkdir($defaultRoot, 0755, true));
            self::assertNotFalse(file_put_contents($defaultRoot.'/sentinel.txt', 'default-unchanged'));
            $default = new PrivateVaultDirectory($project);
            self::assertSame('tightened', $default->tightenPrivatePermissions());
            clearstatcache(true, $defaultRoot);
            self::assertSame(0, ((int) fileperms($defaultRoot)) & 0077);
            self::assertSame('default-unchanged', file_get_contents($defaultRoot.'/sentinel.txt'));
            self::assertSame('already_private', $default->tightenPrivatePermissions());

            self::assertTrue(chmod($external, 0700));
            self::assertTrue(symlink($external, $base.'/alias'));
            $symlinked = new PrivateVaultDirectory($project, $base.'/alias');
            self::assertSame('root_unavailable', $symlinked->tightenPrivatePermissions());
            self::assertSame('unchanged', file_get_contents($external.'/sentinel.txt'));
        } finally {
            @unlink($base.'/alias');
            @unlink($external.'/sentinel.txt');
            @unlink($project.'/var/vault/sentinel.txt');
            @rmdir($project.'/var/vault');
            @rmdir($project.'/var');
            @rmdir($external);
            @rmdir($project.'/public');
            @rmdir($project);
            @rmdir($base.'/checkout');
            @rmdir($base);
        }
    }

    public function testRejectsTraversalWebRootAndSymlinkInsteadOfOpeningPublicMedia(): void
    {
        $base = sys_get_temp_dir().'/gf-vault-root-'.bin2hex(random_bytes(6));
        $project = $base.'/checkout/symfony';
        self::assertTrue(mkdir($project.'/public', 0700, true));
        self::assertTrue(mkdir($base.'/media', 0700));
        try {
            foreach ([
                'relative/path',
                $base.'/../unsafe',
                $base.'/./unsafe',
                $base.'/unsafe'."\0".'/vault',
                $base.'/unsafe\\vault',
            ] as $invalid) {
                try {
                    (new PrivateVaultDirectory($project, $invalid))->root();
                    self::fail('Unsafe Vault root was accepted.');
                } catch (\InvalidArgumentException) {
                    // Strictly reject ambiguous filesystem paths.
                }
            }
            foreach ([$project.'/public', $base.'/checkout/media', $base.'/checkout'] as $withinRelease) {
                try {
                    (new PrivateVaultDirectory($project, $withinRelease))->root();
                    self::fail('Release-owned Vault root was accepted.');
                } catch (\RuntimeException) {
                    // Contents must survive releases independently.
                }
            }
            self::assertTrue(symlink($base.'/media', $base.'/alias'));
            try {
                (new PrivateVaultDirectory($project, $base.'/alias'))->root();
                self::fail('Symbolic link was accepted.');
            } catch (\RuntimeException) {
                // No configured symlink roots.
            }
        } finally {
            @unlink($base.'/alias');
            @rmdir($base.'/media');
            @rmdir($project.'/public');
            @rmdir($project);
            @rmdir($base.'/checkout');
            @rmdir($base);
        }
    }
}
