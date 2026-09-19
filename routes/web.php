<?php

use App\Http\Controllers\Admin\DiagnosticsController;
use App\Http\Controllers\Admin\RunMigrationsController;
use App\Http\Controllers\Admin\SystemController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Connections\DropboxConnectionController;
use App\Http\Controllers\Connections\GoogleDriveConnectionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Scheduling\SchedulerController;
use App\Http\Controllers\Traffic\TrackedLinkRedirectController;
use App\Http\Controllers\Traffic\TrafficController;
use App\Http\Controllers\Vault\DirectUploadController;
use App\Http\Controllers\Vault\VaultController;
use App\Http\Middleware\RequireSchedulingSchema;
use App\Http\Middleware\RequireTrafficSchema;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::get('/l/{token}', TrackedLinkRedirectController::class)
    ->middleware('throttle:120,1')
    ->where('token', '[A-Za-z0-9]{22}')
    ->name('traffic.redirect');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
});

Route::middleware(['auth', 'tenant.user'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)
        ->name('dashboard');

    Route::prefix('/organizations/{organizationId}')
        ->middleware('tenant.organization')
        ->group(function (): void {
            Route::get('/vault', [VaultController::class, 'index'])
                ->name('organizations.vault.index');
            Route::post('/vault', [VaultController::class, 'store'])
                ->name('organizations.vault.store');
            Route::get('/scheduler', [SchedulerController::class, 'index'])
                ->name('organizations.scheduler.index');
            Route::post('/scheduler', [SchedulerController::class, 'store'])
                ->middleware(RequireSchedulingSchema::class)
                ->name('organizations.scheduler.store');
            Route::get('/traffic', [TrafficController::class, 'index'])
                ->name('organizations.traffic.index');
            Route::post('/traffic', [TrafficController::class, 'store'])
                ->middleware(RequireTrafficSchema::class)
                ->name('organizations.traffic.store');
            Route::post('/vault/direct-upload', [DirectUploadController::class, 'create'])
                ->middleware('throttle:30,1')
                ->name('organizations.vault.direct.create');
            Route::post('/vault/direct-upload/complete', [DirectUploadController::class, 'complete'])
                ->middleware('throttle:30,1')
                ->name('organizations.vault.direct.complete');
            Route::get('/connections/dropbox/authorize', [DropboxConnectionController::class, 'authorize'])
                ->middleware('throttle:10,1')
                ->name('organizations.connections.dropbox.authorize');
            Route::get('/connections/google-drive/authorize', [GoogleDriveConnectionController::class, 'authorize'])
                ->middleware('throttle:10,1')
                ->name('organizations.connections.google-drive.authorize');
        });

    Route::get('/connections/dropbox/callback', [DropboxConnectionController::class, 'callback'])
        ->middleware('throttle:20,1')
        ->name('connections.dropbox.callback');
    Route::get('/connections/google-drive/callback', [GoogleDriveConnectionController::class, 'callback'])
        ->middleware('throttle:20,1')
        ->name('connections.google-drive.callback');

    Route::get('/admin/system', SystemController::class)
        ->name('admin.system');
    Route::post('/admin/system/migrations', RunMigrationsController::class)
        ->name('admin.system.migrate');
    Route::get('/admin/diagnostics', [DiagnosticsController::class, 'index'])
        ->name('admin.diagnostics');
    Route::get('/admin/diagnostics.json', [DiagnosticsController::class, 'json'])
        ->name('admin.diagnostics.json');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
