<?php

use Illuminate\Support\Facades\Route;
use Modules\Example\Http\ExampleController;

/*
| Routes of the module. The service provider wraps them in the middleware "web", "auth" and
| "module:example". Kiosk routes add "kiosk.device" and "kiosk" and skip "auth" with
| withoutMiddleware, because the kiosk has its own session.
*/

Route::get('/example', [ExampleController::class, 'index'])->middleware('can:example.view')->name('example.index');

Route::get('/kiosk/example', [ExampleController::class, 'kiosk'])
    ->withoutMiddleware('auth')
    ->middleware(['kiosk.device', 'kiosk'])
    ->name('example.kiosk');
