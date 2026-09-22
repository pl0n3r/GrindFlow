<?php

declare(strict_types=1);

/**
 * Capture metadata-only structure for Symfony-owned gf_* tables.
 *
 * Safety contract:
 * - explicit GF_METADATA_SNAPSHOT_APPROVED=1 opt-in is mandatory;
 * - only information_schema is queried;
 * - application rows are never selected;
 * - credentials, schema name and connection errors are never emitted.
 */
const GF_SNAPSHOT_CONTRACT = 'gf-arch-002-db-structure-snapshot-v1';

/** @return array{host:string,port:int,database:string,user:string,password:string} */
function parseDatabaseUrl(string $url): array
{
    $parts = parse_url($url);
    if (! is_array($parts)) {
        throw new RuntimeException('invalid DATABASE_URL');
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if (! in_array($scheme, ['mysql', 'mariadb'], true)) {
        throw new RuntimeException('DATABASE_URL must use mysql or mariadb');
    }

    $database = ltrim((string) ($parts['path'] ?? ''), '/');
    $host = (string) ($parts['host'] ?? '');
    $user = rawurldecode((string) ($parts['user'] ?? ''));
    if ($database === '' || $host === '' || $user === '') {
        throw new RuntimeException('DATABASE_URL is incomplete');
    }

    return [
        'host' => $host,
        'port' => (int) ($parts['port'] ?? 3306),
        'database' => rawurldecode($database),
        'user' => $user,
        'password' => rawurldecode((string) ($parts['pass'] ?? '')),
    ];
}

function connectMetadataDatabase(array $config): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['host'],
        $config['port'],
        $config['database'],
    );

    return new PDO(
        $dsn,
        $config['user'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );
}

/** @return list<array<string,mixed>> */
function queryRows(PDO $pdo, string $sql, string $schema): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute(['schema' => $schema]);
    $rows = $statement->fetchAll();
    if (! is_array($rows)) {
        throw new RuntimeException('metadata query returned invalid result');
    }

    return $rows;
}

/** @return array<string,array<string,mixed>> */
function emptyTables(array $tableRows): array
{
    $tables = [];
    foreach ($tableRows as $row) {
        $name = (string) ($row['TABLE_NAME'] ?? '');
        if ($name === '' || ! str_starts_with($name, 'gf_')) {
            throw new RuntimeException('invalid gf_ table metadata');
        }
        $tables[$name] = [
            'name' => $name,
            'columns' => [],
            'indexes' => [],
            'foreign_keys' => [],
        ];
    }

    return $tables;
}

function addColumns(array &$tables, array $rows): void
{
    foreach ($rows as $row) {
        $table = (string) ($row['TABLE_NAME'] ?? '');
        if (! isset($tables[$table])) {
            throw new RuntimeException('column references unknown table');
        }

        $name = (string) ($row['COLUMN_NAME'] ?? '');
        $type = strtolower((string) ($row['COLUMN_TYPE'] ?? ''));
        $nullable = strtoupper((string) ($row['IS_NULLABLE'] ?? '')) === 'YES';
        if ($name === '' || $type === '') {
            throw new RuntimeException('invalid column metadata');
        }

        $tables[$table]['columns'][] = [
            'name' => $name,
            'type' => $type,
            'nullable' => $nullable,
        ];
    }
}

function addIndexes(array &$tables, array $rows): void
{
    $grouped = [];
    foreach ($rows as $row) {
        $table = (string) ($row['TABLE_NAME'] ?? '');
        $name = (string) ($row['INDEX_NAME'] ?? '');
        $column = (string) ($row['COLUMN_NAME'] ?? '');
        if (! isset($tables[$table]) || $name === '' || $column === '') {
            throw new RuntimeException('invalid index metadata');
        }

        $key = $table."\0".$name;
        $unique = (int) ($row['NON_UNIQUE'] ?? 1) === 0;
        if (! isset($grouped[$key])) {
            $grouped[$key] = [
                'table' => $table,
                'row' => ['name' => $name, 'unique' => $unique, 'columns' => []],
            ];
        } elseif ($grouped[$key]['row']['unique'] !== $unique) {
            throw new RuntimeException('inconsistent index metadata');
        }
        $grouped[$key]['row']['columns'][] = $column;
    }

    foreach ($grouped as $entry) {
        $tables[$entry['table']]['indexes'][] = $entry['row'];
    }
}

function addForeignKeys(array &$tables, array $rows): void
{
    $grouped = [];
    foreach ($rows as $row) {
        $table = (string) ($row['TABLE_NAME'] ?? '');
        $name = (string) ($row['CONSTRAINT_NAME'] ?? '');
        $column = (string) ($row['COLUMN_NAME'] ?? '');
        $referencedTable = (string) ($row['REFERENCED_TABLE_NAME'] ?? '');
        $referencedColumn = (string) ($row['REFERENCED_COLUMN_NAME'] ?? '');
        $deleteRule = strtoupper((string) ($row['DELETE_RULE'] ?? ''));

        if (
            ! isset($tables[$table])
            || $name === ''
            || $column === ''
            || $referencedTable === ''
            || $referencedColumn === ''
            || $deleteRule === ''
        ) {
            throw new RuntimeException('invalid foreign key metadata');
        }

        $key = $table."\0".$name;
        if (! isset($grouped[$key])) {
            $grouped[$key] = [
                'table' => $table,
                'row' => [
                    'name' => $name,
                    'columns' => [],
                    'referenced_table' => $referencedTable,
                    'referenced_columns' => [],
                    'on_delete' => $deleteRule,
                ],
            ];
        }

        $target = &$grouped[$key]['row'];
        if ($target['referenced_table'] !== $referencedTable || $target['on_delete'] !== $deleteRule) {
            throw new RuntimeException('inconsistent foreign key metadata');
        }
        $target['columns'][] = $column;
        $target['referenced_columns'][] = $referencedColumn;
        unset($target);
    }

    foreach ($grouped as $entry) {
        $tables[$entry['table']]['foreign_keys'][] = $entry['row'];
    }
}

/** @return list<array{name:string,table:string,timing:string,event:string}> */
function normalizeTriggers(array $rows): array
{
    $triggers = [];
    foreach ($rows as $row) {
        $name = (string) ($row['TRIGGER_NAME'] ?? '');
        $table = (string) ($row['EVENT_OBJECT_TABLE'] ?? '');
        $timing = strtoupper((string) ($row['ACTION_TIMING'] ?? ''));
        $event = strtoupper((string) ($row['EVENT_MANIPULATION'] ?? ''));
        if ($name === '' || ! str_starts_with($table, 'gf_') || $timing === '' || $event === '') {
            throw new RuntimeException('invalid trigger metadata');
        }
        $triggers[] = compact('name', 'table', 'timing', 'event');
    }

    usort($triggers, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

    return $triggers;
}

function sortTables(array &$tables): void
{
    foreach ($tables as &$table) {
        foreach (['columns', 'indexes', 'foreign_keys'] as $field) {
            usort(
                $table[$field],
                static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']),
            );
        }
    }
    unset($table);
    ksort($tables);
}

/** @return array<string,mixed> */
function captureSnapshot(PDO $pdo, string $schema): array
{
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->beginTransaction();

    try {
        $tables = emptyTables(queryRows(
            $pdo,
            "SELECT TABLE_NAME
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = :schema
               AND TABLE_TYPE = 'BASE TABLE'
               AND LEFT(TABLE_NAME, 3) = 'gf_'
             ORDER BY TABLE_NAME",
            $schema,
        ));

        $columnRows = queryRows(
            $pdo,
            "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = :schema
               AND LEFT(TABLE_NAME, 3) = 'gf_'
             ORDER BY TABLE_NAME, ORDINAL_POSITION",
            $schema,
        );
        addColumns($tables, $columnRows);

        $indexRows = queryRows(
            $pdo,
            "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = :schema
               AND LEFT(TABLE_NAME, 3) = 'gf_'
             ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX",
            $schema,
        );
        addIndexes($tables, $indexRows);

        $foreignKeyRows = queryRows(
            $pdo,
            "SELECT
                 k.TABLE_NAME,
                 k.CONSTRAINT_NAME,
                 k.COLUMN_NAME,
                 k.REFERENCED_TABLE_NAME,
                 k.REFERENCED_COLUMN_NAME,
                 r.DELETE_RULE
             FROM information_schema.KEY_COLUMN_USAGE k
             INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
              AND r.TABLE_NAME = k.TABLE_NAME
              AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
             WHERE k.CONSTRAINT_SCHEMA = :schema
               AND k.REFERENCED_TABLE_NAME IS NOT NULL
               AND LEFT(k.TABLE_NAME, 3) = 'gf_'
             ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION",
            $schema,
        );
        addForeignKeys($tables, $foreignKeyRows);

        $triggers = normalizeTriggers(queryRows(
            $pdo,
            "SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION
             FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = :schema
               AND LEFT(EVENT_OBJECT_TABLE, 3) = 'gf_'
             ORDER BY TRIGGER_NAME",
            $schema,
        ));

        sortTables($tables);
        $pdo->commit();

        return [
            'contract' => GF_SNAPSHOT_CONTRACT,
            'metadata_only' => true,
            'contains_row_data' => false,
            'scope' => 'gf_*',
            'tables' => array_values($tables),
            'triggers' => $triggers,
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function main(): int
{
    if (getenv('GF_METADATA_SNAPSHOT_APPROVED') !== '1') {
        fwrite(STDERR, "ERROR: metadata snapshot requires explicit approval.\n");

        return 2;
    }

    $databaseUrl = getenv('DATABASE_URL');
    if (! is_string($databaseUrl) || $databaseUrl === '') {
        fwrite(STDERR, "ERROR: DATABASE_URL is required.\n");

        return 2;
    }

    try {
        $config = parseDatabaseUrl($databaseUrl);
        $pdo = connectMetadataDatabase($config);
        $snapshot = captureSnapshot($pdo, $config['database']);
        echo json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";

        return 0;
    } catch (Throwable) {
        fwrite(STDERR, "ERROR: metadata snapshot could not be captured.\n");

        return 2;
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(main());
}
