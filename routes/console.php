<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('grindflow:status', function (): int {
    return 0;
})->purpose('Verify the GrindFlow Laravel application can boot.');
