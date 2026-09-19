<?php

namespace App\Support\Operations;

use Illuminate\Database\Migrations\Migrator;
use RuntimeException;

class MigrationReadiness
{
    public function __construct(
        private readonly Migrator $migrator,
    ) {}

    /**
     * @return array{names: list<string>, fingerprint: string}
     */
    public function snapshot(): array
    {
        $files = $this->migrator->getMigrationFiles(
            database_path('migrations'),
        );

        $ran = $this->migrator->repositoryExists()
            ? $this->migrator->getRepository()->getRan()
            : [];

        $pending = array_diff_key(
            $files,
            array_fill_keys($ran, true),
        );

        ksort($pending);

        $checksums = [];

        foreach ($pending as $name => $path) {
            $checksum = hash_file('sha256', $path);

            if ($checksum === false) {
                throw new RuntimeException(
                    'Unable to inspect a pending migration.',
                );
            }

            $checksums[] = $name.':'.$checksum;
        }

        return [
            'names' => array_keys($pending),
            'fingerprint' => hash(
                'sha256',
                implode("\n", $checksums),
            ),
        ];
    }
}
