<?php

use Illuminate\Support\Facades\Route;
use Modules\Loans\Http\LoanPageController;
use Modules\Loans\Http\PackPinController;

/*
| Routes without login: the pack pin on the public page of a box (/s/{code}), limited like the short
| links, and the borrower page of a loan (/v/{loan}/{token}, documentation/LOANS.md), checked by
| the middleware "loan.page" and limited per address ("loan-page").
*/

Route::middleware('throttle:short-links')->group(function () {
    Route::post('/s/{code}/pack-pin', [PackPinController::class, 'unlock'])->where('code', '[^/]+')->name('loans.pack-pin');
    Route::post('/s/{code}/box-check', [PackPinController::class, 'store'])->where('code', '[^/]+')->name('loans.box-check');
});

Route::prefix('/v/{loan}/{token}')->where(['loan' => '[0-9]+', 'token' => '[A-Za-z0-9_-]{43}'])
    ->middleware(['throttle:loan-page', 'loan.page'])->name('loans.page.')->group(function () {
        Route::get('/', [LoanPageController::class, 'show'])->name('show');
        Route::get('/poll', [LoanPageController::class, 'poll'])->name('poll');
        Route::get('/manifest.webmanifest', [LoanPageController::class, 'manifest'])->name('manifest');
        Route::get('/documents/{document}', [LoanPageController::class, 'document'])->whereNumber('document')->name('document');
        Route::post('/messages', [LoanPageController::class, 'message'])->middleware('throttle:loan-page-messages')->name('messages');
        Route::post('/ticks', [LoanPageController::class, 'tick'])->name('ticks');
        Route::post('/extension', [LoanPageController::class, 'extension'])->middleware('throttle:loan-page-messages')->name('extension');
        Route::post('/pin', [LoanPageController::class, 'pin'])->middleware('throttle:loan-page-pin')->name('pin');
        Route::post('/push', [LoanPageController::class, 'subscribe'])->middleware('throttle:loan-page-push')->name('push');
        Route::delete('/push', [LoanPageController::class, 'unsubscribe'])->name('push.destroy');
        Route::put('/notifications', [LoanPageController::class, 'notifications'])->name('notifications');
    });
