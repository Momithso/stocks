<?php

use Illuminate\Support\Facades\Route;
use Modules\Example\Http\ExampleController;

/*
| Routes of the module on the kiosk (documentation/KIOSK.md). Only what is listed here exists on
| the kiosk. The kiosk module registers the file under /kiosk with the names "kiosk.…" and its
| middleware: the device, the unlocked person (logged in for this request) and "module:example".
| Permissions are checked as on the web.
*/

Route::get('/example', [ExampleController::class, 'index'])->middleware('can:example.view')->name('example.index');
