<?php

namespace App\Support\Demo;

use App\Actions\Setup\InstallStocks;
use App\Models\User;
use App\Support\Extensions\DemoData;
use App\Support\Modules\ModuleRegistry;
use App\Support\Notifications\NotificationEvents;
use App\Support\Settings;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\PermissionRegistrar;

/**
 * Demo data for development and trying out Stocks: an installed instance with one account per
 * start role, then the parts of the core and of all modules (App\Support\Extensions\DemoData).
 * Everything is created through the actions, so numbers, history and audit are real.
 *
 * The data come from a fixed seed: the same seed gives the same items with the same numbers, so
 * the test label sheet stays valid after every "migrate:fresh --seed". See documentation/DEMO.md.
 */
class DemoGenerator
{
    /**
     * Seed used by "migrate:fresh --seed" and "stocks:demo".
     */
    public const SEED = 2026;

    /**
     * E-mail address of the demo admin account.
     */
    public const ADMIN_EMAIL = 'admin@example.com';

    public function __construct(
        private readonly InstallStocks $installStocks,
        private readonly ModuleRegistry $modules,
        private readonly DemoData $demoData,
        private readonly Settings $settings,
    ) {}

    /**
     * Create the demo data in an empty database.
     */
    public function run(int $seed = self::SEED): DemoContext
    {
        // the database was emptied: cached settings, modules and permissions are stale
        Cache::flush();
        // jobs still waiting in the queue belong to the old data (e.g. mails to its contacts)
        rescue(fn () => Artisan::call('queue:clear', ['--force' => true]), report: false);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // all modules except the example are on, so every part adds its data
        $this->installStocks->handle(
            settings: ['organization.name' => 'Demo-Fachschaft', 'organization.short_name' => 'Demo', 'legal.contact_email' => 'fsr@example.com', 'organization.address' => "Demo-Fachschaft\nEupener Straße 70\n52066 Aachen", 'organization.phone' => '0241 6009 12345'],
            admin: ['firstname' => 'Ada', 'lastname' => 'Admin', 'email' => self::ADMIN_EMAIL, 'password' => UserFactory::PASSWORD],
            activeModules: array_values(array_diff(array_keys($this->modules->all()), ['example'])),
        );
        $admin = User::where('email', self::ADMIN_EMAIL)->firstOrFail();
        $admin->forceFill(['pin' => UserFactory::PIN])->save();

        foreach (array_keys(config('stocks.start_roles')) as $index => $role) {
            User::factory()->withPin()->create([
                'firstname' => $role,
                'lastname' => 'Demo',
                'email' => 'demo'.($index + 1).'@example.com',
            ])->assignRole($role);
        }

        $demo = new DemoContext($seed, $admin);
        // the generator sends nothing: no mails, no push
        NotificationEvents::quietly(function () use ($demo) {
            foreach ($this->demoData->parts() as $class) {
                app($class)->run($demo);
            }
        });

        $this->settings->set(['demo.seeded_at' => now()->toIso8601String(), 'demo.labels' => $demo->labels()]);

        return $demo;
    }

    /**
     * Check if the instance holds demo data.
     */
    public function hasDemoData(): bool
    {
        return $this->settings->get('demo.seeded_at') !== null;
    }
}
