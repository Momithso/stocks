<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Modules\ToggleModule;
use App\Http\Controllers\Controller;
use App\Support\Modules\ModuleManifest;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Switch modules on and off.
 */
class ModuleController extends Controller
{
    /**
     * Display all modules of the folder modules/.
     */
    public function index(ModuleRegistry $modules): Response
    {
        return Inertia::render('Admin/Modules/Index', [
            'modules' => array_values(array_map(fn (ModuleManifest $module) => [
                'key' => $module->key,
                'name' => $module->name,
                'description' => $module->description,
                'version' => $module->version,
                'requires' => array_map(fn (string $key) => $modules->find($key)->name ?? $key, $module->requires),
                'active' => $modules->isActive($module->key),
            ], $modules->all())),
        ]);
    }

    /**
     * Switch a module on or off.
     */
    public function update(Request $request, string $key, ToggleModule $toggleModule): RedirectResponse
    {
        $active = $request->validate(['active' => ['required', 'boolean']])['active'];
        $toggleModule->handle($key, $active);

        return back()->with('success', $active ? 'Das Modul ist eingeschaltet.' : 'Das Modul ist ausgeschaltet.');
    }
}
