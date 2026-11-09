<?php

namespace Tests;

use App\Actions\Setup\InstallStocks;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Set up the test environment: an installed instance with the start roles.
     *
     * Inertia sends every page to the ssr server of a running vite dev server (public/hot). Tests must not
     * depend on it, an unreachable dev server delays every page by the connection timeout.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['inertia.ssr.enabled' => false]);

        app(InstallStocks::class)->handle([], null);
    }

    /**
     * Create an account with the given roles.
     */
    protected function userWithRoles(string ...$roles): User
    {
        return User::factory()->create()->assignRole($roles);
    }

    /**
     * Create an account with the admin role.
     */
    protected function admin(): User
    {
        return $this->userWithRoles(Role::admin());
    }

    /**
     * Create an account with a role that has exactly the given permissions.
     */
    protected function userWithPermissions(string ...$permissions): User
    {
        $role = Role::create(['name' => 'Test '.implode(', ', $permissions).' '.uniqid(), 'guard_name' => 'web']);
        $role->syncPermissions($permissions);

        return User::factory()->create()->assignRole($role);
    }

    /**
     * Switch a module on.
     */
    protected function activateModule(string $key): void
    {
        Module::where('key', $key)->update(['active' => true]);
        app(ModuleRegistry::class)->flush();
    }

    /**
     * Enable model auditing, which is disabled for console runs (and therefore PHPUnit) by default.
     */
    protected function enableModelAuditing(): void
    {
        config(['audit.console' => true]);
    }
}
