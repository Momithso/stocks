<?php

namespace App\Actions\Roles;

use App\Models\Role;
use App\Models\User;
use App\Support\Permissions\GrantCheck;
use Illuminate\Validation\ValidationException;

/**
 * Delete a role that no account has.
 */
class DeleteRole
{
    public function __construct(private readonly GrantCheck $grantCheck) {}

    public function handle(User $actor, Role $role): void
    {
        if ($role->is_system) {
            throw ValidationException::withMessages(['role' => 'Systemrollen können nicht gelöscht werden.']);
        }

        if (! $this->grantCheck->canGrantRole($actor, $role)) {
            throw ValidationException::withMessages(['role' => 'Diese Rolle hat Rechte, die du selbst nicht hast. Du kannst sie deshalb nicht löschen.']);
        }

        if ($role->users()->exists()) {
            throw ValidationException::withMessages(['role' => 'Die Rolle ist noch Konten zugewiesen. Entferne sie dort zuerst.']);
        }

        $role->delete();
    }
}
