<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/routes',
    ])
    ->withPhpSets()
    ->withDeadCodeLevel(0)
    ->withCodeQualityLevel(0)
    ->withSets([
        LaravelSetList::LARAVEL_CODE_QUALITY,
    ]);
