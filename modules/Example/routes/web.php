<?php

use Illuminate\Support\Facades\Route;
use Modules\Example\Http\ExampleController;

/*
| Routes of the module. The service provider wraps them in the middleware "web", "auth" and
| "module:example". Which of them also exist on the kiosk lists routes/kiosk.php.
*/

Route::get('/example', [ExampleController::class, 'index'])->middleware('can:example.view')->name('example.index');
