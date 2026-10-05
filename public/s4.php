<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use GrindFlow\Kernel;
use Symfony\Component\HttpFoundation\Request;

const S4_PREFLIGHT_CONTRACT = 's4-bridge-readiness-v1';
const S4_SYNTHETIC_IDENTITY = 'e2e-oidc-smoke@grindflow.test';

function s4State(string $state, int $status): never
{
    $allowed = [
        'runtime_unavailable',
        'config_missing',
        'schema_missing',
        'identity_unavailable',
        'ready_for_web_probe',
    ];
    if (! in_array($state, $allowed, true)) {
        $state = 'runtime_unavailable';
        $status = 503;
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('X-GrindFlow-S4-State: '.$state);
    echo json_encode([
        'data' => [
            'contract' => S4_PREFLIGHT_CONTRACT,
            'state' => $state,
        ],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = parse_url($requestUri, PHP_URL_PATH);

if (! is_string($path) || ($path !== '/s4' && ! str_starts_with($path, '/s4/'))) {
    http_response_code(404);
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    exit;
}

$symfonyRoot = dirname(__DIR__).'/symfony';
$bootstrap = $symfonyRoot.'/config/bootstrap.php';
$autoload = $symfonyRoot.'/vendor/autoload.php';

if (! is_file($bootstrap) || ! is_file($autoload)) {
    s4State('runtime_unavailable', 503);
}

/*
 * Apache rewrites /s4/... to this file while REQUEST_URI intentionally keeps
 * the public prefix. Pretending the executable lives at /s4/index.php makes
 * HttpFoundation derive baseUrl=/s4 and pathInfo=/..., so Symfony keeps its
 * existing internal routes while generated URLs stay inside the /s4 bridge.
 */
$_SERVER['SCRIPT_NAME'] = '/s4/index.php';
$_SERVER['PHP_SELF'] = '/s4/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__.'/s4/index.php';

try {
    require $bootstrap;
} catch (Throwable) {
    s4State('runtime_unavailable', 503);
}

if ($path === '/s4/_bridge-readiness') {
    $appSecret = (string) ($_SERVER['APP_SECRET'] ?? $_ENV['APP_SECRET'] ?? getenv('APP_SECRET') ?: '');
    $databaseUrl = (string) ($_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL') ?: '');
    if ($appSecret === '' || $databaseUrl === '') {
        s4State('config_missing', 503);
    }

    try {
        $connection = DriverManager::getConnection(['url' => $databaseUrl]);
        $schema = $connection->createSchemaManager();
        if (! $schema->tablesExist([
            'gf_identity_users',
            'gf_identity_organizations',
            'gf_identity_memberships',
        ])) {
            s4State('schema_missing', 503);
        }
        $identityCount = (int) $connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM gf_identity_users actor
                INNER JOIN gf_identity_memberships membership ON membership.user_id = actor.id
                WHERE actor.email = :email AND actor.is_active = 1
                SQL,
            ['email' => S4_SYNTHETIC_IDENTITY],
        );
        $connection->close();
    } catch (Throwable) {
        s4State('schema_missing', 503);
    }

    if ($identityCount < 1) {
        s4State('identity_unavailable', 503);
    }

    s4State('ready_for_web_probe', 200);
}

$kernel = new Kernel(
    $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'prod',
    filter_var($_SERVER['APP_DEBUG'] ?? $_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL),
);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->headers->set('X-GrindFlow-S4-Bridge', '1');
$response->send();
$kernel->terminate($request, $response);
