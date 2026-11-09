<?php

use App\Actions\Setup\InstallStocks;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Commands of the core
|--------------------------------------------------------------------------
*/

Artisan::command('stocks:sync', function (ModuleRegistry $modules) {
    $modules->sync();
    $this->info('Module, Rechte und die Admin-Rolle sind aktuell.');
})->purpose('Create module switches, permissions and the admin role (run after every update)');

Artisan::command('stocks:install
    {--name= : Name der Organisation}
    {--short-name= : Kurzname der Organisation}
    {--admin-firstname= : Vorname des ersten Admin-Kontos}
    {--admin-lastname= : Nachname des ersten Admin-Kontos}
    {--admin-email= : E-Mail-Adresse des ersten Admin-Kontos}
    {--admin-password= : Passwort des ersten Admin-Kontos (sonst wird gefragt)}
    {--module=* : Schlüssel der Module, die eingeschaltet werden}', function (InstallStocks $installStocks) {
    $email = $this->option('admin-email');
    $admin = null;
    if ($email) {
        $admin = [
            'firstname' => $this->option('admin-firstname') ?? 'Admin',
            'lastname' => $this->option('admin-lastname') ?? '',
            'email' => $email,
            'password' => $this->option('admin-password') ?? $this->secret('Passwort für '.$email),
        ];
    }

    $settings = array_filter([
        'organization.name' => $this->option('name'),
        'organization.short_name' => $this->option('short-name') ?? $this->option('name'),
    ]);

    $installStocks->handle($settings, $admin, activeModules: $this->option('module'));
    $this->info('Stocks ist eingerichtet.');
})->purpose('Set up the instance without the wizard (safe to run again)');
