<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Roles\DeleteRole;
use App\Actions\Roles\SaveRole;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Support\Modules\ModuleRegistry;
use App\Support\Permissions\GrantCheck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Role management. Nobody can grant a permission he does not have himself (see GrantCheck).
 */
class RoleController extends Controller
{
    /**
     * Display all roles.
     */
    public function index(Request $request, GrantCheck $grantCheck): Response
    {
        return Inertia::render('Admin/Roles/Index', [
            'roles' => Role::with('permissions:id,name')->withCount('users')->orderByDesc('is_system')->orderBy('name')->get()
                ->map(fn (Role $role) => [
                    ...$role->only(['id', 'name', 'description', 'is_system', 'users_count']),
                    'permissions_count' => $role->permissions->count(),
                    'editable' => ! $role->is_system && $grantCheck->canGrantRole($request->user(), $role),
                ]),
        ]);
    }

    /**
     * Display the form for a new role.
     */
    public function create(Request $request, ModuleRegistry $modules): Response
    {
        return $this->form($request, $modules, null);
    }

    /**
     * Display the form of a role.
     */
    public function edit(Request $request, ModuleRegistry $modules, Role $role): Response
    {
        return $this->form($request, $modules, $role);
    }

    /**
     * Create a role.
     */
    public function store(Request $request, SaveRole $saveRole): RedirectResponse
    {
        $validated = $this->validated($request, null);
        $role = $saveRole->handle($request->user(), null, $validated['name'], $validated['description'] ?? null, $validated['permissions'] ?? []);

        return redirect()->route('admin.roles.index')->with('success', "Die Rolle {$role->name} wurde angelegt.");
    }

    /**
     * Change a role.
     */
    public function update(Request $request, Role $role, SaveRole $saveRole): RedirectResponse
    {
        $validated = $this->validated($request, $role);
        $saveRole->handle($request->user(), $role, $validated['name'], $validated['description'] ?? null, $validated['permissions'] ?? []);

        return redirect()->route('admin.roles.index')->with('success', "Die Rolle {$role->name} wurde gespeichert.");
    }

    /**
     * Delete a role.
     */
    public function destroy(Request $request, Role $role, DeleteRole $deleteRole): RedirectResponse
    {
        $deleteRole->handle($request->user(), $role);

        return redirect()->route('admin.roles.index')->with('success', "Die Rolle {$role->name} wurde gelöscht.");
    }

    /**
     * The form shows all permissions grouped by core and module; permissions the actor does not
     * have are shown, but can not be selected.
     */
    private function form(Request $request, ModuleRegistry $modules, ?Role $role): Response
    {
        $own = $request->user()->getAllPermissions()->pluck('name')->sort()->values()->all();

        return Inertia::render('Admin/Roles/Edit', [
            'role' => $role?->only(['id', 'name', 'description', 'is_system']),
            'rolePermissions' => $role?->permissions->pluck('name') ?? [],
            'permissionGroups' => $modules->permissionGroups(),
            'ownPermissions' => $own,
        ]);
    }

    /**
     * @return array{name: string, description?: string|null, permissions?: list<string>}
     */
    private function validated(Request $request, ?Role $role): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('roles', 'name')->ignore($role)],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);
    }
}
