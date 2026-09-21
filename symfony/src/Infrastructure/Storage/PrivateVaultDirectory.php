<?php

declare(strict_types=1);

namespace GrindFlow\Infrastructure\Storage;

/**
 * Private Vault filesystem root. An optional external directory survives
 * release-directory replacement when the operator provides durable storage.
 * This does not create or verify backups.
 */
final class PrivateVaultDirectory
{
    public function __construct(
        private readonly string $projectDir,
        private readonly string $rootOverride = '',
    ) {
    }

    public function root(): string
    {
        if ($this->rootOverride === '') {
            $root = $this->projectDir.'/var/vault';
            if (is_link($root)) {
                throw new \RuntimeException('Private Vault storage cannot be a symbolic link.');
            }

            return $root;
        }

        $configured = rtrim($this->rootOverride, '/');
        if ($configured === '' || !str_starts_with($configured, '/')
            || str_contains($configured, "\0") || str_contains($configured, '\\')
            || preg_match('#(?:^|/)\.{1,2}(?:/|$)#D', $configured) === 1) {
            throw new \InvalidArgumentException('Private Vault storage requires a clean absolute path.');
        }
        // Require an operator-provisioned existing parent. Never create an
        // arbitrary hierarchy by interpreting a malformed environment value.
        $parent = realpath(dirname($configured));
        $release = realpath(dirname($this->projectDir));
        if ($parent === false || $release === false) {
            throw new \RuntimeException('Private Vault parent or release directory is unavailable.');
        }
        $root = rtrim($parent, '/').'/'.basename($configured);
        if (is_link($configured) || is_link($root)) {
            throw new \RuntimeException('Private Vault storage cannot be a symbolic link.');
        }
        $resolved = is_dir($root) ? realpath($root) : $root;
        if ($resolved === false || $resolved === $release || str_starts_with($resolved, $release.'/')) {
            throw new \RuntimeException('External Vault storage must be outside the release tree.');
        }

        return $root;
    }

    /** Create only the final private directory; its parent must already exist. */
    public function ensureWritable(): string
    {
        $root = $this->root();
        if (!is_dir($root) && !mkdir($root, 0700, $this->rootOverride === '') && !is_dir($root)) {
            throw new \RuntimeException('Private Vault storage is not available.');
        }
        if (is_link($root) || !is_dir($root) || !is_writable($root)) {
            throw new \RuntimeException('Private Vault storage is not writable.');
        }
        // A pre-existing external directory must not make private originals
        // accessible to group members or other users on a shared host.
        if ($this->rootOverride !== '') {
            $mode = @fileperms($root);
            if ($mode === false || ($mode & 0077) !== 0) {
                throw new \RuntimeException('External Vault directory requires private permissions.');
            }
        }

        return $root;
    }
}
