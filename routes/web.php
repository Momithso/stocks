<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\KioskDeviceController;
use App\Http\Controllers\Admin\ModuleController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Inventory\CategoryController;
use App\Http\Controllers\Inventory\ConditionController;
use App\Http\Controllers\Inventory\ItemController;
use App\Http\Controllers\Inventory\ItemHistoryController;
use App\Http\Controllers\Inventory\ItemPhotoController;
use App\Http\Controllers\Inventory\LocationController;
use App\Http\Controllers\Inventory\MoveController;
use App\Http\Controllers\Inventory\UnitController;
use App\Http\Controllers\KioskController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SetupController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Routes of the core
|--------------------------------------------------------------------------
|
| Modules register their own routes in modules/<Module>/routes/web.php behind
| the middleware "module:<key>".
|
*/

Route::get('/setup', [SetupController::class, 'show'])->name('setup.show');

// logo and favicon, also for guests (login page, browser tab)
Route::get('/branding/{kind}', [BrandingController::class, 'show'])->whereIn('kind', ['logo', 'favicon'])->name('branding.show');
Route::post('/setup', [SetupController::class, 'store'])->middleware('throttle:10,1')->name('setup.store');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');

    Route::get('/forgot-password', [PasswordResetController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'store'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.update');

    Route::get('/invitation/{token}', [InvitationController::class, 'show'])->name('invitation.show');
    Route::post('/invitation/{token}', [InvitationController::class, 'store'])->middleware('throttle:10,1')->name('invitation.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::redirect('/', '/dashboard');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('/styleguide', fn () => Inertia::render('Styleguide'))->middleware('can:settings.manage')->name('styleguide');

    // inventory, see documentation/INVENTORY.md
    Route::prefix('inventory')->name('inventory.')->middleware('can:inventory.view')->group(function () {
        Route::get('/items', [ItemController::class, 'index'])->name('items.index');
        Route::get('/misplaced', [ItemController::class, 'misplaced'])->name('items.misplaced');
        Route::get('/lookup', [ItemController::class, 'lookup'])->name('lookup');
        Route::get('/places', [ItemController::class, 'places'])->name('places');
        Route::get('/stats', [ItemController::class, 'stats'])->name('stats');
        Route::get('/photos/{photo}', [ItemPhotoController::class, 'show'])->name('photos.show');

        Route::middleware('can:inventory.edit')->group(function () {
            Route::get('/items/create', [ItemController::class, 'create'])->name('items.create');
            Route::post('/items', [ItemController::class, 'store'])->name('items.store');
            Route::get('/items/{item}/edit', [ItemController::class, 'edit'])->name('items.edit');
            Route::put('/items/{item}', [ItemController::class, 'update'])->name('items.update');
            Route::post('/items/{item}/photos', [ItemPhotoController::class, 'store'])->name('items.photos.store');
            Route::post('/items/{item}/photos/{photo}/cover', [ItemPhotoController::class, 'cover'])->scopeBindings()->name('items.photos.cover');
            Route::delete('/items/{item}/photos/{photo}', [ItemPhotoController::class, 'destroy'])->scopeBindings()->name('items.photos.destroy');
        });

        Route::get('/items/{item}', [ItemController::class, 'show'])->name('items.show');

        Route::middleware('can:inventory.move')->group(function () {
            Route::get('/move', [MoveController::class, 'create'])->name('move');
            Route::post('/move', [MoveController::class, 'store'])->name('move.store');
            Route::post('/items/{item}/return-home', [ItemController::class, 'returnHome'])->name('items.return-home');
            Route::post('/items/{item}/condition', [ItemController::class, 'changeCondition'])->name('items.condition');
            Route::post('/items/{item}/consume', [ItemController::class, 'consume'])->name('items.consume');
            Route::post('/items/{item}/merge', [ItemController::class, 'merge'])->name('items.merge');
        });

        Route::middleware('can:inventory.retire')->group(function () {
            Route::post('/items/{item}/retire', [ItemController::class, 'retire'])->name('items.retire');
            Route::delete('/items/{item}', [ItemController::class, 'destroy'])->name('items.destroy');
        });

        Route::middleware('can:inventory.history.delete')->scopeBindings()->group(function () {
            Route::delete('/items/{item}/movements/{movement}', [ItemHistoryController::class, 'destroyMovement'])->name('items.movements.destroy');
            Route::delete('/items/{item}/condition-changes/{conditionChange}', [ItemHistoryController::class, 'destroyConditionChange'])->name('items.condition-changes.destroy');
        });

        Route::get('/locations', [LocationController::class, 'index'])->name('locations.index');
        Route::get('/locations/{location}', [LocationController::class, 'show'])->name('locations.show');

        Route::middleware('can:locations.manage')->group(function () {
            Route::post('/locations', [LocationController::class, 'store'])->name('locations.store');
            Route::put('/locations/{location}', [LocationController::class, 'update'])->name('locations.update');
            Route::delete('/locations/{location}', [LocationController::class, 'destroy'])->name('locations.destroy');

            Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
            Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
            Route::put('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
            Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
        });

        Route::middleware('can:inventory.options.manage')->group(function () {
            Route::get('/conditions', [ConditionController::class, 'index'])->name('conditions.index');
            Route::post('/conditions', [ConditionController::class, 'store'])->name('conditions.store');
            Route::put('/conditions/{condition}', [ConditionController::class, 'update'])->name('conditions.update');
            Route::delete('/conditions/{condition}', [ConditionController::class, 'destroy'])->name('conditions.destroy');

            Route::get('/units', [UnitController::class, 'index'])->name('units.index');
            Route::post('/units', [UnitController::class, 'store'])->name('units.store');
            Route::put('/units/{unit}', [UnitController::class, 'update'])->name('units.update');
            Route::delete('/units/{unit}', [UnitController::class, 'destroy'])->name('units.destroy');
        });
    });

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::middleware('can:users.view')->group(function () {
            Route::get('/users', [UserController::class, 'index'])->name('users.index');
            Route::post('/users/invite', [UserController::class, 'invite'])->middleware('can:users.invite')->name('users.invite');
            Route::delete('/invitations/{invitation}', [UserController::class, 'destroyInvitation'])->middleware('can:users.invite')->name('invitations.destroy');
            // changing roles additionally needs roles.manage and is checked by the action (grant rule)
            Route::put('/users/{user}', [UserController::class, 'update'])->middleware('can:users.manage')->name('users.update');
            Route::post('/users/{user}/reset-pin', [UserController::class, 'resetPin'])->middleware('can:users.manage')->name('users.reset-pin');
            Route::delete('/users/{user}', [UserController::class, 'destroy'])->middleware('can:users.delete')->name('users.destroy');
        });

        Route::middleware('can:roles.manage')->group(function () {
            Route::resource('roles', RoleController::class)->except('show');
        });

        Route::middleware('can:modules.manage')->group(function () {
            Route::get('/modules', [ModuleController::class, 'index'])->name('modules.index');
            Route::put('/modules/{key}', [ModuleController::class, 'update'])->name('modules.update');
        });

        Route::middleware('can:settings.manage')->group(function () {
            Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
            Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
            Route::post('/settings/branding', [BrandingController::class, 'update'])->name('settings.branding');
        });

        Route::middleware('can:kiosk.devices.manage')->group(function () {
            Route::get('/kiosk-devices', [KioskDeviceController::class, 'index'])->name('kiosk-devices.index');
            Route::post('/kiosk-devices', [KioskDeviceController::class, 'store'])->name('kiosk-devices.store');
            Route::post('/kiosk-devices/{device}/regenerate-token', [KioskDeviceController::class, 'regenerateToken'])->name('kiosk-devices.regenerate-token');
            Route::delete('/kiosk-devices/{device}', [KioskDeviceController::class, 'destroy'])->name('kiosk-devices.destroy');
        });

        Route::get('/audit-log', [AuditLogController::class, 'index'])->middleware('can:audit.view')->name('audit-log');
    });
});

/*
|--------------------------------------------------------------------------
| Kiosk
|--------------------------------------------------------------------------
|
| Only for paired tablets (cookie). The kiosk session is separate from the
| normal login, see App\Support\Kiosk\KioskSession.
|
*/

Route::get('/kiosk/pair/{device}', [KioskController::class, 'pair'])->middleware('throttle:10,1')->name('kiosk.pair');

Route::prefix('kiosk')->name('kiosk.')->middleware('kiosk.device')->group(function () {
    Route::get('/', [KioskController::class, 'lock'])->name('lock');
    Route::post('/unlock', [KioskController::class, 'unlock'])->name('unlock');
    Route::post('/logout', [KioskController::class, 'logout'])->name('logout');

    Route::middleware('kiosk')->group(function () {
        Route::get('/home', [KioskController::class, 'home'])->name('home');
    });
});
