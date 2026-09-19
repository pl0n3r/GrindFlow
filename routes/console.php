<?php

use App\Services\Distribution\DistributionScheduler;
use App\Services\Media\Connections\MediaConnectionScheduler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('grindflow:status', function (): int {
    return 0;
})->purpose('Verify the GrindFlow Laravel application can boot.');

Artisan::command('grindflow:dispatch-media-scans', function (): int {
    $dispatched = app(MediaConnectionScheduler::class)->dispatchDue();

    $this->info("Dispatched {$dispatched} media connection scan(s).");

    return 0;
})->purpose('Dispatch due tenant-scoped media connection scans.');

Artisan::command('grindflow:dispatch-publications', function (): int {
    $dispatched = app(DistributionScheduler::class)->dispatchDue();

    $this->info("Dispatched {$dispatched} publication delivery job(s).");

    return 0;
})->purpose('Dispatch due tenant-scoped scheduled publications.');

Schedule::command('grindflow:dispatch-media-scans')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

Schedule::command('grindflow:dispatch-publications')
    ->everyMinute()
    ->withoutOverlapping(5);
