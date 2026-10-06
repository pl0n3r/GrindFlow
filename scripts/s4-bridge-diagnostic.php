#!/usr/bin/env php
<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

const S4_DIAGNOSTIC_TABLES = [
    'gf_identity_users',
    'gf_identity_organizations',
    'gf_identity_memberships',
];
const S4_DIAGNOSTIC_EMAIL = 'e2e-oidc-smoke@grindflow.test';
const S4_DIAGNOSTIC_CODES = [
    'runtime_missing',
    'app_secret_missing',
    'app_secret_too_short',
    'database_url_missing',
    'database_url_invalid',
    'database_connection_failed',
    'schema_missing:gf_identity_users',
    'schema_missing:gf_identity_organizations',
    'schema_missing:gf_identity_memberships',
    'identity_missing',
    'ready',
];

function diagnosticEnv(string $name): string
{
    $value = $_SERVER[$name]
        ?? $_ENV[$name]
        ?? getenv($name)
        ?: '';

    return is_string($value) ? trim($value) : '';
}

function diagnosticFinish(string $code): never
{
    if (! in_array($code, S4_DIAGNOSTIC_CODES, true)) {
        $code = 'database_connection_failed';
    }

    echo json_encode(
        [
            'status' => $code === 'ready' ? 'ready' : 'blocked',
            'code' => $code,
        ],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
    ).PHP_EOL;

    exit($code === 'ready' ? 0 : 2);
}

function databaseUrlIsValid(string $databaseUrl): bool
{
    $parts = parse_url($databaseUrl);
    if (! is_array($parts)) {
        return false;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = trim((string) ($parts['host'] ?? ''));
    $user = trim((string) ($parts['user'] ?? ''));
    $path = trim((string) ($parts['path'] ?? ''), '/');

    return in_array($scheme, ['mysql', 'mariadb'], true)
        && $host !== ''
        && $user !== ''
        && $path !== '';
}

function diagnosticConfigurationCode(string $appSecret, string $databaseUrl): ?string
{
    if ($appSecret === '') {
        return 'app_secret_missing';
    }
    if (strlen($appSecret) < 32) {
        return 'app_secret_too_short';
    }
    if ($databaseUrl === '') {
        return 'database_url_missing';
    }
    if (! databaseUrlIsValid($databaseUrl)) {
        return 'database_url_invalid';
    }

    return null;
}

/**
 * @param  array<string, bool>  $tables
 */
function diagnosticDatabaseCode(bool $connected, array $tables, int $identityCount): string
{
    if (! $connected) {
        return 'database_connection_failed';
    }

    foreach (S4_DIAGNOSTIC_TABLES as $table) {
        if (($tables[$table] ?? false) !== true) {
            return 'schema_missing:'.$table;
        }
    }

    return $identityCount < 1 ? 'identity_missing' : 'ready';
}

function diagnosticOfflineScenario(): string
{
    if (diagnosticEnv('APP_ENV') !== 'test') {
        return '';
    }

    $scenario = diagnosticEnv('GRINDFLOW_S4_DIAGNOSTIC_TEST_SCENARIO');
    if ($scenario === '') {
        return '';
    }
    if (! in_array($scenario, S4_DIAGNOSTIC_CODES, true)) {
        diagnosticFinish('database_connection_failed');
    }

    return $scenario;
}

function runDiagnosticOfflineScenario(string $scenario): never
{
    $appSecret = diagnosticEnv('GRINDFLOW_S4_DIAGNOSTIC_TEST_SECRET');
    $databaseUrl = diagnosticEnv('GRINDFLOW_S4_DIAGNOSTIC_TEST_DATABASE_URL');

    if ($scenario === 'runtime_missing') {
        diagnosticFinish('runtime_missing');
    }
    if ($scenario === 'app_secret_missing') {
        $appSecret = '';
    } elseif ($scenario === 'app_secret_too_short') {
        $appSecret = 'short';
    } elseif ($scenario === 'database_url_missing') {
        $databaseUrl = '';
    } elseif ($scenario === 'database_url_invalid') {
        $databaseUrl = 'invalid-database-url';
    }

    $configurationCode = diagnosticConfigurationCode($appSecret, $databaseUrl);
    if ($configurationCode !== null) {
        diagnosticFinish($configurationCode);
    }

    $tables = array_fill_keys(S4_DIAGNOSTIC_TABLES, true);
    if (str_starts_with($scenario, 'schema_missing:')) {
        $missingTable = substr($scenario, strlen('schema_missing:'));
        if (array_key_exists($missingTable, $tables)) {
            $tables[$missingTable] = false;
        }
    }

    diagnosticFinish(diagnosticDatabaseCode(
        $scenario !== 'database_connection_failed',
        $tables,
        $scenario === 'identity_missing' ? 0 : 1,
    ));
}

$offlineScenario = diagnosticOfflineScenario();
if ($offlineScenario !== '') {
    runDiagnosticOfflineScenario($offlineScenario);
}

$symfonyRoot = dirname(__DIR__).'/symfony';
$bootstrap = $symfonyRoot.'/config/bootstrap.php';
$autoload = $symfonyRoot.'/vendor/autoload.php';

if (! is_file($bootstrap) || ! is_file($autoload)) {
    diagnosticFinish('runtime_missing');
}

try {
    require $bootstrap;
} catch (Throwable) {
    diagnosticFinish('runtime_missing');
}

$appSecret = diagnosticEnv('APP_SECRET');
$databaseUrl = diagnosticEnv('DATABASE_URL');
$configurationCode = diagnosticConfigurationCode($appSecret, $databaseUrl);
if ($configurationCode !== null) {
    diagnosticFinish($configurationCode);
}

try {
    $params = (new DsnParser([
        'mysql' => 'pdo_mysql',
        'mariadb' => 'pdo_mysql',
    ]))->parse($databaseUrl);
    $connection = DriverManager::getConnection($params);
    $connection->executeQuery('SELECT 1');
} catch (Throwable) {
    diagnosticFinish(diagnosticDatabaseCode(false, [], 0));
}

$tables = [];
$identityCount = 0;

try {
    $schema = $connection->createSchemaManager();
    foreach (S4_DIAGNOSTIC_TABLES as $table) {
        $tables[$table] = $schema->tablesExist([$table]);
    }

    if (! in_array(false, $tables, true)) {
        $identityCount = (int) $connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM gf_identity_users actor
                INNER JOIN gf_identity_memberships membership ON membership.user_id = actor.id
                WHERE actor.email = :email AND actor.is_active = 1
                SQL,
            ['email' => S4_DIAGNOSTIC_EMAIL],
        );
    }
    $connection->close();
} catch (Throwable) {
    try {
        $connection->close();
    } catch (Throwable) {
        // No output: diagnostics remain allowlisted and secret-free.
    }
    diagnosticFinish('database_connection_failed');
}

diagnosticFinish(diagnosticDatabaseCode(true, $tables, $identityCount));
