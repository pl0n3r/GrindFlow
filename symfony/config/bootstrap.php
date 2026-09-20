<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

$projectDir = dirname(__DIR__);
require $projectDir.'/vendor/autoload.php';

if (class_exists(Dotenv::class) && is_file($projectDir.'/.env')) {
    (new Dotenv())->usePutenv()->bootEnv($projectDir.'/.env');
}
