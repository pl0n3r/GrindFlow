#!/usr/bin/env php
<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;

const S4_DIAGNOSTIC_TABLES = [
    'gf_identity_users',
    'gf_identity_organizations',
    'gf_identity_memberships',
];
const S4_DIAGNOSTIC_EMAIL = 'e2e-oidc-smoke@grindflow.test';
const S4_DIAGNOSTIC_CODES = [
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

$symfonyRoot = dirname(__DIR__).'/symfony';
$bootstrap = $symfonyRoot.'/config/bootstrap.php';
$autoload = $symfonyRoot.'/vendor/autoload.php';

if (! is_file($bootstrap) || ! is_file($autoload)) {
    diagnosticFinish('database_connection_failed');
}

try {
    require $bootstrap;
} catch (Throwable) {
    diagnosticFinish('database_connection_failed');
}

$appSecret = diagnosticEnv('APP_SECRET');
if ($appSecret === '') {
    diagnosticFinish('app_secret_missing');
}
if (strlen($appSecret) < 32) {
    diagnosticFinish('app_secret_too_short');
}

$databaseUrl = diagnosticEnv('DATABASE_URL');
if ($databaseUrl === '') {
    diagnosticFinish('database_url_missing');
}
if (! databaseUrlIsValid($databaseUrl)) {
    diagnosticFinish('database_url_invalid');
}

try {
    $connection = DriverManager::getConnection(['url' => $databaseUrl]);
    $connection->connect();
} catch (Throwable) {
    diagnosticFinish('database_connection_failed');
}

try {
    $schema = $connection->createSchemaManager();
    foreach (S4_DIAGNOSTIC_TABLES as $table) {
        if (! $schema->tablesExist([$table])) {
            $connection->close();
            diagnosticFinish('schema_missing:'.$table);
        }
    }

    $identityCount = (int) $connection->fetchOne(
        <<<'SQL'
            SELECT COUNT(*)
            FROM gf_identity_users actor
            INNER JOIN gf_identity_memberships membership ON membership.user_id = actor.id
            WHERE actor.email = :email AND actor.is_active = 1
            SQL,
        ['email' => S4_DIAGNOSTIC_EMAIL],
    );
    $connection->close();
} catch (Throwable) {
    try {
        $connection->close();
    } catch (Throwable) {
        // No output: diagnostics remain allowlisted and secret-free.
    }
    diagnosticFinish('database_connection_failed');
}

if ($identityCount < 1) {
    diagnosticFinish('identity_missing');
}

diagnosticFinish('ready');
