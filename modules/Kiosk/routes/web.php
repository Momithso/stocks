<?php

use Illuminate\Support\Facades\Route;
use Modules\Kiosk\Http\DeviceController;
use Modules\Kiosk\Http\KioskController;

/*
| Routes of the kiosk itself. The service provider wraps them in "web" and "module:kiosk".
| The kiosk session is separate from the normal login, see Modules\Kiosk\Support\KioskSession.
| Routes of other modules on the kiosk come from their routes/kiosk.php (SurfaceRoutes).
*/

Route::get('/kiosk/pair/{device}', [KioskController::class, 'pair'])->middleware('throttle:10,1')->name('kiosk.pair');

Route::prefix('kiosk')->name('kiosk.')->middleware('kiosk.device')->group(function () {
    Route::get('/', [KioskController::class, 'lock'])->name('lock');
    Route::post('/unlock', [KioskController::class, 'unlock'])->name('unlock');
    Route::post('/logout', [KioskController::class, 'logout'])->name('logout');
    Route::get('/manifest.webmanifest', [KioskController::class, 'manifest'])->name('manifest');

    Route::middleware('kiosk')->group(function () {
        Route::get('/home', [KioskController::class, 'home'])->name('home');
        Route::post('/extend', [KioskController::class, 'extend'])->name('extend');
        Route::get('/done', [KioskController::class, 'done'])->name('done');
    });
});

Route::middleware(['auth', 'can:kiosk.devices.manage'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/kiosk-devices', [DeviceController::class, 'index'])->name('kiosk-devices.index');
    Route::post('/kiosk-devices', [DeviceController::class, 'store'])->name('kiosk-devices.store');
    Route::put('/kiosk-devices/{device}', [DeviceController::class, 'update'])->name('kiosk-devices.update');
    Route::post('/kiosk-devices/{device}/regenerate-token', [DeviceController::class, 'regenerateToken'])->name('kiosk-devices.regenerate-token');
    Route::delete('/kiosk-devices/{device}', [DeviceController::class, 'destroy'])->name('kiosk-devices.destroy');
    Route::put('/kiosk-settings', [DeviceController::class, 'settings'])->name('kiosk-settings.update');
});
