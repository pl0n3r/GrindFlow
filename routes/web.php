<?php

use App\Http\Controllers\Admin\DiagnosticsController;
use App\Http\Controllers\Admin\SystemController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Vault\VaultController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

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
        });

    Route::get('/admin/system', SystemController::class)
        ->name('admin.system');
    Route::get('/admin/diagnostics', [DiagnosticsController::class, 'index'])
        ->name('admin.diagnostics');
    Route::get('/admin/diagnostics.json', [DiagnosticsController::class, 'json'])
        ->name('admin.diagnostics.json');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
