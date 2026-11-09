<?php

namespace App\Actions\Roles;

use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Permissions\GrantCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create a role or change its name, description and permissions.
 */
class SaveRole
{
    public function __construct(private readonly GrantCheck $grantCheck) {}

    /**
     * The actor must have every permission he puts into the role, and for an existing role also
     * every permission it already has. System roles can not be changed.
     *
     * @param  list<string>  $permissions
     */
    public function handle(User $actor, ?Role $role, string $name, ?string $description, array $permissions): Role
    {
        if ($role?->is_system) {
            throw ValidationException::withMessages(['name' => 'Systemrollen können nicht geändert werden.']);
        }

        if ($role && ! $this->grantCheck->canGrantRole($actor, $role)) {
            throw ValidationException::withMessages(['permissions' => 'Diese Rolle hat Rechte, die du selbst nicht hast. Du kannst sie deshalb nicht ändern.']);
        }

        if (! $this->grantCheck->canGrantPermissions($actor, $permissions)) {
            throw ValidationException::withMessages(['permissions' => 'Du kannst nur Rechte vergeben, die du selbst hast.']);
        }

        return DB::transaction(function () use ($role, $name, $description, $permissions) {
            $role ??= new Role(['guard_name' => 'web']);
            $oldPermissions = $role->exists ? $role->permissions->pluck('name')->sort()->values()->all() : [];

            $role->fill(['name' => $name, 'description' => $description])->save();
            $role->syncPermissions($permissions);

            $newPermissions = collect($permissions)->sort()->values()->all();
            if ($oldPermissions !== $newPermissions) {
                AuditRecorder::record($role, 'permissionsUpdated', ['permissions' => $oldPermissions], ['permissions' => $newPermissions]);
            }

            return $role;
        });
    }
}
