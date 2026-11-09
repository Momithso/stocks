<?php

namespace App\Support\Modules;

use App\Models\Module;
use App\Models\Role;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;

/**
 * Knows all modules in the folder modules/ and whether they are active.
 *
 * Every module is a folder with a module.json and a service provider. The providers of all modules
 * are always registered and their migrations always run, so switching a module off loses no data.
 * Routes of a module are protected by the middleware "module:<key>", which answers 404 while the
 * module is off. Routes are not left out instead, because Octane keeps the route list of the
 * worker until it restarts.
 */
class ModuleRegistry
{
    /**
     * Cache key of the keys of the active modules.
     *
     * @var string
     */
    private const CACHE_KEY = 'stocks.modules.active';

    /**
     * Manifests of all modules by key.
     *
     * @var array<string, ModuleManifest>
     */
    private array $manifests = [];

    public function __construct(string $modulesPath)
    {
        foreach (glob($modulesPath.'/*/module.json') ?: [] as $file) {
            $manifest = ModuleManifest::fromFile($file);
            $this->manifests[$manifest->key] = $manifest;
        }

        ksort($this->manifests);
    }

    /**
     * @return array<string, ModuleManifest>
     */
    public function all(): array
    {
        return $this->manifests;
    }

    public function find(string $key): ?ModuleManifest
    {
        return $this->manifests[$key] ?? null;
    }

    /**
     * Check if a module is active. Unknown modules are never active.
     */
    public function isActive(string $key): bool
    {
        return isset($this->manifests[$key]) && in_array($key, $this->activeKeys(), true);
    }

    /**
     * Get the keys of the active modules.
     *
     * @return list<string>
     */
    public function activeKeys(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => Module::where('active', true)->pluck('key')->all());
    }

    /**
     * Forget the cached active modules, e.g. after a module was switched on or off.
     */
    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Get the keys of the active modules that require the given module.
     *
     * @return list<string>
     */
    public function activeDependents(string $key): array
    {
        return array_values(array_filter(
            $this->activeKeys(),
            fn (string $activeKey) => in_array($key, $this->manifests[$activeKey]?->requires ?? [], true),
        ));
    }

    /**
     * Get the required modules of a module that are not active.
     *
     * @return list<string>
     */
    public function missingRequirements(string $key): array
    {
        return array_values(array_filter(
            $this->manifests[$key]?->requires ?? [],
            fn (string $required) => ! $this->isActive($required),
        ));
    }

    /**
     * All permissions grouped by core and module, with their German description.
     *
     * @return list<array{group: string, module: string|null, permissions: array<string, string>}>
     */
    public function permissionGroups(): array
    {
        $groups = [['group' => 'Kern', 'module' => null, 'permissions' => config('stocks.permissions')]];

        foreach ($this->manifests as $manifest) {
            if ($manifest->permissions) {
                $groups[] = ['group' => $manifest->name, 'module' => $manifest->key, 'permissions' => $manifest->permissions];
            }
        }

        return $groups;
    }

    /**
     * Bring the database in line with the module folders: a switch for every module (new modules
     * start inactive), every permission of the core and the modules, and the admin role with all
     * permissions. Safe to run again at any time.
     */
    public function sync(): void
    {
        foreach (array_keys($this->manifests) as $key) {
            Module::firstOrCreate(['key' => $key], ['active' => false]);
        }

        $names = [];
        foreach ($this->permissionGroups() as $group) {
            foreach (array_keys($group['permissions']) as $name) {
                Permission::findOrCreate($name, 'web');
                $names[] = $name;
            }
        }

        $admin = Role::firstOrCreate(['name' => Role::admin(), 'guard_name' => 'web'], ['is_system' => true]);
        $admin->forceFill(['is_system' => true, 'description' => 'Hat alle Rechte, auch die von später hinzugefügten Modulen.'])->save();
        $admin->syncPermissions($names);

        $this->flush();
    }
}
