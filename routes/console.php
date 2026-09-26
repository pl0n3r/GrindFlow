<?php

use App\Services\Distribution\DistributionScheduler;
use App\Services\Media\Connections\MediaConnectionScheduler;
use App\Services\Traffic\TrafficAttributionRecorder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('grindflow:status', fn (): int => 0)->purpose('Verify the GrindFlow Laravel application can boot.');

Artisan::command('grindflow:dispatch-media-scans', function (): int {
    $dispatched = resolve(MediaConnectionScheduler::class)->dispatchDue();

    $this->info("Dispatched {$dispatched} media connection scan(s).");

    return 0;
})->purpose('Dispatch due tenant-scoped media connection scans.');

Artisan::command('grindflow:dispatch-publications', function (): int {
    $dispatched = resolve(DistributionScheduler::class)->dispatchDue();

    $this->info("Dispatched {$dispatched} publication delivery job(s).");

    return 0;
})->purpose('Dispatch due tenant-scoped scheduled publications.');

Schedule::command('grindflow:dispatch-media-scans')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

Schedule::command('grindflow:dispatch-publications')
    ->everyMinute()
    ->withoutOverlapping(5);

Artisan::command('grindflow:prune-traffic-dedupes', function (): int {
    $deleted = resolve(TrafficAttributionRecorder::class)->pruneExpired();

    $this->info("Pruned {$deleted} expired traffic dedupe row(s).");

    return 0;
})->purpose('Prune privacy-sensitive traffic dedupe hashes after their retention window.');

Schedule::command('grindflow:prune-traffic-dedupes')
    ->hourly()
    ->withoutOverlapping(5);

Schedule::command('grindflow:provision-smoke-user')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->when(static fn (): bool => app()->environment('production')
        && config('grindflow.phase') === 'construccion'
        && trim((string) config('grindflow.smoke_user.password')) !== '');
