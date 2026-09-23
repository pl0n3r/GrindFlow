<?php

namespace App\Support\Deployment;

class CheckoutIdentity
{
    public function commit(?string $basePath = null): ?string
    {
        $root = rtrim($basePath ?? base_path(), DIRECTORY_SEPARATOR);
        $gitEntry = $root.DIRECTORY_SEPARATOR.'.git';
        $gitDirectory = $this->gitDirectory($gitEntry);

        if ($gitDirectory === null) {
            return null;
        }

        $head = $this->readTrimmed($gitDirectory.DIRECTORY_SEPARATOR.'HEAD');

        if ($head === null) {
            return null;
        }

        if ($this->isCommit($head)) {
            return strtolower($head);
        }

        if (preg_match('/^ref: (refs\/[A-Za-z0-9._\/-]+)$/', $head, $match) !== 1) {
            return null;
        }

        $reference = $match[1];

        if (str_contains($reference, '..')) {
            return null;
        }

        $loose = $this->readTrimmed(
            $gitDirectory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $reference),
        );

        if ($loose !== null && $this->isCommit($loose)) {
            return strtolower($loose);
        }

        return $this->packedReference($gitDirectory, $reference);
    }

    private function gitDirectory(string $gitEntry): ?string
    {
        if (is_dir($gitEntry)) {
            $resolved = realpath($gitEntry);

            return $resolved === false ? null : $resolved;
        }

        if (! is_file($gitEntry)) {
            return null;
        }

        $pointer = $this->readTrimmed($gitEntry);

        if ($pointer === null || preg_match('/^gitdir: (.+)$/', $pointer, $match) !== 1) {
            return null;
        }

        $candidate = $match[1];

        if (! str_starts_with($candidate, DIRECTORY_SEPARATOR)) {
            $candidate = dirname($gitEntry).DIRECTORY_SEPARATOR.$candidate;
        }

        $resolved = realpath($candidate);

        return $resolved !== false && is_dir($resolved) ? $resolved : null;
    }

    private function packedReference(string $gitDirectory, string $reference): ?string
    {
        $packed = $gitDirectory.DIRECTORY_SEPARATOR.'packed-refs';

        if (! is_file($packed)) {
            return null;
        }

        $handle = fopen($packed, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '^')) {
                    continue;
                }

                [$sha, $name] = array_pad(preg_split('/\\s+/', $line, 2) ?: [], 2, null);

                if ($name === $reference && is_string($sha) && $this->isCommit($sha)) {
                    return strtolower($sha);
                }
            }
        } finally {
            fclose($handle);
        }

        return null;
    }

    private function readTrimmed(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        return trim($contents);
    }

    private function isCommit(string $value): bool
    {
        return preg_match('/^[0-9a-f]{40}$/i', $value) === 1;
    }
}
