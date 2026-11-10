<?php

namespace Database\Seeders;

use App\Actions\Setup\InstallStocks;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Database\Seeder;

/**
 * Demo data for local development: an installed instance with one account per start role and a
 * small inventory. All accounts have the password of UserFactory::PASSWORD and the pin UserFactory::PIN.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(InstallStocks $installStocks): void
    {
        $installStocks->handle(
            settings: ['organization.name' => 'Demo-Fachschaft', 'organization.short_name' => 'Demo'],
            admin: ['firstname' => 'Ada', 'lastname' => 'Admin', 'email' => 'admin@example.com', 'password' => UserFactory::PASSWORD],
            activeModules: ['example'],
        );
        User::where('email', 'admin@example.com')->update(['pin' => bcrypt(UserFactory::PIN)]);

        foreach (array_keys(config('stocks.start_roles')) as $index => $role) {
            User::factory()->withPin()->create([
                'firstname' => $role,
                'lastname' => 'Demo',
                'email' => 'demo'.($index + 1).'@example.com',
            ])->assignRole($role);
        }

        $this->call(InventorySeeder::class);
    }
}
