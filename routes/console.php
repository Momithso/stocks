<?php

use App\Actions\Setup\InstallStocks;
use App\Models\Item;
use App\Models\User;
use App\Notifications\TestNotification;
use App\Support\Demo\DemoGenerator;
use App\Support\Demo\TestSheet;
use App\Support\Modules\ModuleRegistry;
use App\Support\Notifications\WebPush;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

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

Artisan::command('stocks:demo {--seed=2026 : Startwert des Generators} {--force : Auch in einer Produktivinstanz ausführen}', function () {
    if (app()->isProduction() && ! $this->option('force')) {
        $this->error('Das löscht alle Daten. In einer Produktivinstanz nur mit --force.');

        return 1;
    }

    $this->call('migrate:fresh', ['--force' => true]);
    $demo = app(DemoGenerator::class)->run((int) $this->option('seed'));
    $this->info('Demo-Daten erzeugt: '.Item::count().' Gegenstände, '.count($demo->labels()).' Testetiketten. Anmeldung: '.DemoGenerator::ADMIN_EMAIL.' / '.UserFactory::PASSWORD);
})->purpose('Delete all data and generate the demo data (documentation/DEMO.md)');

Artisan::command('stocks:demo-sheet {path=documentation/demo/testetiketten.pdf : Ziel, relativ zum Projektordner}', function (TestSheet $sheet) {
    if (! app(DemoGenerator::class)->hasDemoData()) {
        $this->error('Die Instanz hat keine Demo-Daten. Erst „php artisan stocks:demo“ ausführen.');

        return 1;
    }

    $path = base_path($this->argument('path'));
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $sheet->pdf());
    $this->info("Testetiketten gespeichert: {$this->argument('path')}");
})->purpose('Write the test label sheet of the demo data as pdf');

Artisan::command('stocks:notify-test {email : E-Mail-Adresse eines Kontos} {--channel=mail : mail oder push}', function (string $email) {
    $channel = $this->option('channel');
    if (! in_array($channel, ['mail', 'push'], true)) {
        $this->error('Kanal ist „mail“ oder „push“.');

        return 1;
    }
    $user = User::where('email', mb_strtolower($email))->first();
    if ($user === null) {
        $this->error("Kein Konto mit {$email}.");

        return 1;
    }
    if ($channel === 'push' && ! WebPush::enabled()) {
        $this->error('Push ist aus: VAPID_PUBLIC_KEY und VAPID_PRIVATE_KEY fehlen in der .env (php artisan webpush:vapid).');

        return 1;
    }
    if ($channel === 'push' && ! $user->pushSubscriptions()->exists()) {
        $this->error("{$user->name} hat kein Gerät mit Push. Erst im Profil „Push auf diesem Gerät einschalten“.");

        return 1;
    }

    // at once, not through the queue, so errors show here
    $user->notifyNow(new TestNotification($channel));
    $this->info($channel === 'push'
        ? 'Push an '.$user->pushSubscriptions()->count().' Gerät(e) von '.$user->name.' geschickt.'
        : "Testmail an {$user->email} geschickt.");
})->purpose('Send a test notification by mail or push to an account');
