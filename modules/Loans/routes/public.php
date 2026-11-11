<?php

use Illuminate\Support\Facades\Route;
use Modules\Loans\Http\PackPinController;

/*
| Pack pin on the public page of a box (/s/{code}), without login. Limited like the short links,
| and the pin itself locks after five wrong tries.
*/

Route::middleware('throttle:short-links')->group(function () {
    Route::post('/s/{code}/pack-pin', [PackPinController::class, 'unlock'])->where('code', '[^/]+')->name('loans.pack-pin');
    Route::post('/s/{code}/box-check', [PackPinController::class, 'store'])->where('code', '[^/]+')->name('loans.box-check');
});
