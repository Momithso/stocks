<?php

use App\Http\Controllers\Inventory\ItemController;
use App\Http\Controllers\Inventory\ItemPhotoController;
use App\Http\Controllers\Inventory\MoveController;
use App\Http\Controllers\Inventory\ScanController;
use App\Http\Controllers\Labels\PrintController;
use App\Http\Controllers\Labels\TemplateController;
use Illuminate\Support\Facades\Route;

/*
| Routes of the core on the kiosk: the same controllers with the same permissions, under /kiosk
| and with "kiosk." in front of the name. Only what is listed here exists on the kiosk. The service
| provider adds the middleware "web", "kiosk.device", "kiosk" and "module:kiosk".
*/

Route::prefix('inventory')->name('inventory.')->middleware('can:inventory.view')->group(function () {
    Route::get('/scan', [ScanController::class, 'show'])->name('scan');
    Route::get('/lookup', [ItemController::class, 'lookup'])->name('lookup');
    Route::get('/places', [ItemController::class, 'places'])->name('places');
    Route::get('/photos/{photo}', [ItemPhotoController::class, 'show'])->name('photos.show');

    Route::middleware('can:inventory.move')->group(function () {
        Route::post('/move', [MoveController::class, 'store'])->name('move.store');
        Route::post('/items/{item}/return-home', [ItemController::class, 'returnHome'])->name('items.return-home');
        Route::post('/items/{item}/condition', [ItemController::class, 'changeCondition'])->name('items.condition');
        Route::post('/items/{item}/consume', [ItemController::class, 'consume'])->name('items.consume');
    });
});

Route::prefix('labels')->name('labels.')->middleware('can:labels.print')->group(function () {
    Route::get('/print', [PrintController::class, 'create'])->name('print');
    Route::post('/printed', [PrintController::class, 'printed'])->name('printed');
    Route::post('/confirm-address', [PrintController::class, 'confirmAddress'])->name('confirm-address');
    Route::get('/images/{name}', [TemplateController::class, 'showImage'])->where('name', '[A-Za-z0-9._-]+')->name('images.show');
});
