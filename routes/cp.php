<?php

declare(strict_types=1);

use ElSchneider\ContentMcp\Http\Controllers\ConnectController;
use ElSchneider\ContentMcp\Http\Controllers\ConnectionsController;
use ElSchneider\ContentMcp\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('content-mcp')->name('content-mcp.')->group(function (): void {
    Route::get('/', ConnectController::class)->name('connect');
    Route::get('connections', [ConnectionsController::class, 'index'])->name('connections');
    Route::delete('connections/{client}/{user}', [ConnectionsController::class, 'destroy'])->name('connections.destroy');
    Route::get('settings', SettingsController::class)->name('settings');
});
