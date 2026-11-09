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
use App\Http\Controllers\DashboardController;
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
