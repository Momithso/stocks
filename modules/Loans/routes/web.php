<?php

use Illuminate\Support\Facades\Route;
use Modules\Loans\Http\BorrowerPageController;
use Modules\Loans\Http\CalendarController;
use Modules\Loans\Http\ChatController;
use Modules\Loans\Http\ContactController;
use Modules\Loans\Http\EventController;
use Modules\Loans\Http\IssueController;
use Modules\Loans\Http\LoanController;
use Modules\Loans\Http\PositionController;
use Modules\Loans\Http\ReturnController;
use Modules\Loans\Http\ReturnsController;
use Modules\Loans\Http\SettingsController;

/*
| Routes of the loan module, wrapped in "web", "auth" and "module:loans" by the service provider.
| See documentation/LOANS.md.
*/

// ids are numbers, so "/loans/settings" never matches "/loans/{loan}"
Route::patterns(array_fill_keys(['loan', 'position', 'row', 'document', 'contact', 'event', 'council', 'extensionRequest'], '[0-9]+'));

Route::prefix('loans')->name('loans.')->group(function () {
    Route::middleware('can:loans.view')->group(function () {
        Route::get('/', [LoanController::class, 'index'])->name('index');
        Route::get('/calendar', [CalendarController::class, 'show'])->name('calendar');
        Route::get('/calendar/loans', [CalendarController::class, 'loans'])->name('calendar.loans');
        Route::get('/calendar/timeline', [CalendarController::class, 'timeline'])->name('calendar.timeline');
        Route::get('/items/{item}', [CalendarController::class, 'item'])->name('item');
        Route::get('/stats', [LoanController::class, 'stats'])->name('stats');
        Route::get('/events', [EventController::class, 'index'])->name('events.index');
        Route::get('/events/{event}', [EventController::class, 'show'])->name('events.show');
        Route::get('/{loan}/details', [LoanController::class, 'details'])->name('details');
    });

    Route::middleware('can:loans.manage')->group(function () {
        Route::get('/create', [LoanController::class, 'create'])->name('create');
        Route::post('/', [LoanController::class, 'store'])->name('store');
        Route::get('/add-item/{item}', [LoanController::class, 'addItem'])->name('add-item');
        Route::get('/{loan}/edit', [LoanController::class, 'edit'])->name('edit');
        Route::put('/{loan}', [LoanController::class, 'update'])->name('update');
        Route::delete('/{loan}', [LoanController::class, 'destroy'])->name('destroy');
        Route::post('/{loan}/confirm', [LoanController::class, 'confirm'])->name('confirm');
        Route::post('/{loan}/extend', [LoanController::class, 'extend'])->name('extend');
        Route::post('/{loan}/pin', [LoanController::class, 'pin'])->name('pin');
        Route::post('/{loan}/positions', [PositionController::class, 'store'])->name('positions.store');
        Route::put('/{loan}/positions/{position}', [PositionController::class, 'update'])->scopeBindings()->name('positions.update');
        Route::delete('/{loan}/positions/{position}', [PositionController::class, 'destroy'])->scopeBindings()->name('positions.destroy');
        Route::get('/search-items', [PositionController::class, 'search'])->name('search-items');

        // chat and borrower page (documentation/LOANS.md)
        Route::get('/{loan}/messages', [ChatController::class, 'index'])->name('messages.index');
        Route::post('/{loan}/messages', [ChatController::class, 'store'])->middleware('throttle:30,1')->name('messages.store');
        Route::post('/{loan}/page/block', [BorrowerPageController::class, 'block'])->name('page.block');
        Route::post('/{loan}/page/reissue', [BorrowerPageController::class, 'reissue'])->name('page.reissue');
        Route::post('/{loan}/extension-requests/{extensionRequest}/accept', [BorrowerPageController::class, 'accept'])->scopeBindings()->name('extension.accept');
        Route::post('/{loan}/extension-requests/{extensionRequest}/decline', [BorrowerPageController::class, 'decline'])->scopeBindings()->name('extension.decline');
        Route::post('/{loan}/remind', [BorrowerPageController::class, 'remind'])->middleware('throttle:20,1')->name('remind');

        Route::get('/contacts', [ContactController::class, 'index'])->name('contacts.index');
        Route::get('/contacts/search', [ContactController::class, 'search'])->name('contacts.search');
        Route::post('/contacts', [ContactController::class, 'store'])->name('contacts.store');
        Route::get('/contacts/{contact}', [ContactController::class, 'show'])->name('contacts.show');
        Route::put('/contacts/{contact}', [ContactController::class, 'update'])->name('contacts.update');
        Route::delete('/contacts/{contact}', [ContactController::class, 'destroy'])->name('contacts.destroy');

        Route::post('/events', [EventController::class, 'store'])->name('events.store');
        Route::put('/events/{event}', [EventController::class, 'update'])->name('events.update');
        Route::delete('/events/{event}', [EventController::class, 'destroy'])->name('events.destroy');
    });

    Route::middleware('can:loans.issue')->group(function () {
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

        // the same steps for the return and for the later inspection of what came back unchecked
        Route::get('/{loan}/{mode}', [ReturnController::class, 'show'])->whereIn('mode', ['return', 'inspect'])->name('return.show');
        Route::post('/{loan}/{mode}/check', [ReturnController::class, 'check'])->whereIn('mode', ['return', 'inspect'])->name('return.check');
        Route::post('/{loan}/{mode}/rows/{row}/defect', [ReturnController::class, 'defect'])->whereIn('mode', ['return', 'inspect'])->name('return.defect');
        Route::post('/{loan}/{mode}/rows/{row}/reset', [ReturnController::class, 'reset'])->whereIn('mode', ['return', 'inspect'])->name('return.reset');
        Route::post('/{loan}/return/complete', [ReturnController::class, 'complete'])->name('return.complete');
        Route::post('/{loan}/return/unchecked', [ReturnController::class, 'unchecked'])->name('return.unchecked');
        Route::post('/{loan}/inspect/complete', [ReturnController::class, 'completeInspection'])->name('inspect.complete');
    });

    Route::middleware('can:loans.cancel')->group(function () {
        Route::post('/{loan}/cancel', [LoanController::class, 'cancel'])->name('cancel');
        Route::post('/{loan}/close-defects', [LoanController::class, 'closeDefects'])->name('close-defects');
    });

    Route::middleware('can:loans.settings')->group(function () {
        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::get('/settings/preview.pdf', [SettingsController::class, 'preview'])->name('settings.preview');
        Route::post('/councils', [SettingsController::class, 'storeCouncil'])->name('councils.store');
        Route::put('/councils/{council}', [SettingsController::class, 'updateCouncil'])->name('councils.update');
        Route::delete('/councils/{council}', [SettingsController::class, 'destroyCouncil'])->name('councils.destroy');
    });

    Route::middleware('can:loans.view')->group(function () {
        Route::get('/{loan}', [LoanController::class, 'show'])->name('show');
        Route::get('/{loan}/confirmation.pdf', [LoanController::class, 'confirmation'])->name('confirmation');
        Route::get('/{loan}/documents/{document}', [LoanController::class, 'document'])->scopeBindings()->name('document');
    });
});
