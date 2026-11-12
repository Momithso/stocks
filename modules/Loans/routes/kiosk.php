<?php

use Illuminate\Support\Facades\Route;
use Modules\Loans\Http\ContactController;
use Modules\Loans\Http\IssueController;
use Modules\Loans\Http\KioskLoanController;
use Modules\Loans\Http\LoanController;
use Modules\Loans\Http\PositionController;
use Modules\Loans\Http\ReturnController;
use Modules\Loans\Http\ReturnsController;

/*
| Routes of the loan module on the kiosk (documentation/KIOSK.md): issue, return, inspection,
| putting back and open defects, with the controllers and permissions of the pc. Only what is listed here exists on
| the kiosk; loan details, new loans and settings stay on the pc. The kiosk module registers the
| file under /kiosk with the names "kiosk.…" and "module:loans".
*/

Route::prefix('loans')->name('loans.')->group(function () {
    Route::middleware('can:loans.issue')->group(function () {
        // start page of the kiosk tasks instead of the list of the pc
        Route::get('/', [KioskLoanController::class, 'index'])->name('index');
        Route::get('/find', [KioskLoanController::class, 'find'])->name('find');

        Route::get('/returns', [ReturnsController::class, 'index'])->name('returns');
        Route::post('/returns/stow', [ReturnsController::class, 'stow'])->name('returns.stow');
        Route::post('/{loan}/missing/{row}/found', [ReturnsController::class, 'found'])->name('missing.found');

        Route::get('/{loan}/issue', [IssueController::class, 'show'])->name('issue');
        Route::post('/{loan}/issue/check', [IssueController::class, 'check'])->name('issue.check');
        Route::post('/{loan}/issue/rows/{row}/uncheck', [IssueController::class, 'uncheck'])->name('issue.uncheck');
        Route::post('/{loan}/issue/responsible', [IssueController::class, 'responsible'])->name('issue.responsible');
        Route::post('/{loan}/issue/complete', [IssueController::class, 'complete'])->name('issue.complete');
        Route::post('/{loan}/issue/rows/{row}/defect', [IssueController::class, 'defect'])->name('issue.defect');
        Route::post('/{loan}/issue/rows/{row}/reset-defect', [IssueController::class, 'resetDefect'])->name('issue.reset-defect');
        Route::post('/{loan}/issue/rows/{row}/drop', [IssueController::class, 'drop'])->name('issue.drop');
        Route::get('/{loan}/preview/{type}.pdf', [IssueController::class, 'preview'])->whereIn('type', ['contract', 'list'])->name('preview');

        Route::get('/{loan}/{mode}', [ReturnController::class, 'show'])->whereIn('mode', ['return', 'inspect'])->name('return.show');
        Route::post('/{loan}/{mode}/check', [ReturnController::class, 'check'])->whereIn('mode', ['return', 'inspect'])->name('return.check');
        Route::post('/{loan}/{mode}/rows/{row}/defect', [ReturnController::class, 'defect'])->whereIn('mode', ['return', 'inspect'])->name('return.defect');
        Route::post('/{loan}/{mode}/rows/{row}/reset', [ReturnController::class, 'reset'])->whereIn('mode', ['return', 'inspect'])->name('return.reset');
        Route::post('/{loan}/return/complete', [ReturnController::class, 'complete'])->name('return.complete');
        Route::post('/{loan}/return/unchecked', [ReturnController::class, 'unchecked'])->name('return.unchecked');
        Route::post('/{loan}/inspect/complete', [ReturnController::class, 'completeInspection'])->name('inspect.complete');
    });

    // the window of a loan with its defects (list of loans with defects, returns page)
    Route::get('/{loan}/details', [LoanController::class, 'details'])->middleware('can:loans.view')->name('details');
    Route::post('/{loan}/close-defects', [LoanController::class, 'closeDefects'])->middleware('can:loans.cancel')->name('close-defects');

    // what the issue page offers besides: a foreign item into the loan, another responsible person
    Route::middleware('can:loans.manage')->group(function () {
        Route::post('/{loan}/positions', [PositionController::class, 'store'])->name('positions.store');
        Route::delete('/{loan}/positions/{position}', [PositionController::class, 'destroy'])->scopeBindings()->name('positions.destroy');
        Route::get('/contacts/search', [ContactController::class, 'search'])->name('contacts.search');
        Route::post('/contacts', [ContactController::class, 'store'])->name('contacts.store');
    });
});
