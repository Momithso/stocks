<?php

namespace App\Actions\Setup;

use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Set up a new instance: settings, start roles and the first admin account.
 *
 * Used by the setup wizard (/setup) and the command "stocks:install". Running it again changes
 * the settings and keeps existing roles and accounts.
 */
class InstallStocks
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly Settings $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $settings  values for keys of Settings::DEFAULTS
     * @param  array{firstname: string, lastname: string, email: string, password: string}|null  $admin  first admin account, null to keep the existing ones
     * @param  array<string, string>  $roleNames  new names of the start roles, by their name in config/stocks.php
     * @param  list<string>  $activeModules  keys of the modules to switch on
     */
    public function handle(array $settings, ?array $admin, array $roleNames = [], array $activeModules = []): ?User
    {
        return DB::transaction(function () use ($settings, $admin, $roleNames, $activeModules) {
            $this->modules->sync();
            $this->createStartRoles($roleNames);

            foreach ($activeModules as $key) {
                if ($this->modules->find($key)) {
                    Module::where('key', $key)->update(['active' => true]);
                }
            }
            $this->modules->flush();

            $user = null;
            if ($admin !== null) {
                $user = User::firstOrNew(['email' => mb_strtolower($admin['email'])]);
                $user->fill(['firstname' => $admin['firstname'], 'lastname' => $admin['lastname'], 'is_disabled' => false]);
                $user->password = $admin['password'];
                $user->save();
                $user->assignRole(Role::admin());
            }

            $this->settings->set([
                ...array_intersect_key($settings, Settings::DEFAULTS),
                'setup.completed_at' => $this->settings->get('setup.completed_at') ?? now()->toIso8601String(),
            ]);

            return $user;
        });
    }

    /**
     * Create the start roles of config/stocks.php once. Existing roles are not changed.
     *
     * @param  array<string, string>  $roleNames
     */
    private function createStartRoles(array $roleNames): void
    {
        if (Role::where('is_system', false)->exists()) {
            return;
        }

        foreach (config('stocks.start_roles') as $name => $role) {
            Role::create([
                'name' => ($roleNames[$name] ?? '') !== '' ? $roleNames[$name] : $name,
                'guard_name' => 'web',
                'description' => $role['description'],
            ])->syncPermissions($role['permissions']);
        }
    }
}
