<?php

use Illuminate\Support\Facades\Route;
use SafferIt\LibrenmsNetconf\Http\Controllers\DeviceController;
use SafferIt\LibrenmsNetconf\Http\Controllers\StatusController;

/*
 * Plugin pages. Every route needs a LibreNMS login; the status list needs global read,
 * device pages check the device policy, and anything that changes or talks to a device
 * needs the admin role.
 */
Route::middleware(['web', 'auth'])
    ->prefix('plugin/netconf')
    ->name('netconf.')
    ->group(function (): void {
        Route::middleware('can:global-read')->group(function (): void {
            Route::get('status', [StatusController::class, 'index'])->name('status');
            Route::get('definitions', [StatusController::class, 'definitions'])->name('definitions');
        });

        Route::get('device/{device}', [DeviceController::class, 'show'])->name('device');
        Route::get('device/{device}/metrics', [DeviceController::class, 'metrics'])->name('device.metrics');

        Route::middleware('can:admin')->group(function (): void {
            Route::post('device/{device}', [DeviceController::class, 'update'])->name('device.update');
            Route::post('device/{device}/test', [DeviceController::class, 'test'])->name('device.test');
            Route::post('device/{device}/discover', [DeviceController::class, 'discover'])->name('device.discover');
            Route::post('device/{device}/poll', [DeviceController::class, 'poll'])->name('device.poll');
            Route::post('run', [StatusController::class, 'run'])->name('run');
        });
    });
