<?php

namespace App\Support\Permissions;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The rule of the role management: nobody can grant more than he has himself.
 *
 * Used by every action that changes permissions of roles or roles of accounts. The checks live
 * here and not only in the user interface, so a direct request can not get around them.
 */
class GrantCheck
{
    /**
     * Check if the actor has all of the given permissions himself.
     *
     * @param  iterable<string>  $permissions
     */
    public function canGrantPermissions(User $actor, iterable $permissions): bool
    {
        $own = $this->ownPermissions($actor);

        foreach ($permissions as $permission) {
            if (! $own->contains($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if the actor may give or take away the role: he must have all of its permissions.
     */
    public function canGrantRole(User $actor, Role $role): bool
    {
        return $this->canGrantPermissions($actor, $role->permissions->pluck('name'));
    }

    /**
     * Check if the actor may manage the account (change roles, disable, delete, reset the pin).
     * Nobody manages his own account here, and only accounts whose roles the actor could grant.
     */
    public function canManageUser(User $actor, User $user): bool
    {
        if ($actor->is($user)) {
            return false;
        }

        return $user->roles->every(fn (Role $role) => $this->canGrantRole($actor, $role));
    }

    /**
     * Names of the permissions of the actor (directly and through roles).
     *
     * @return Collection<int, string>
     */
    private function ownPermissions(User $actor): Collection
    {
        return $actor->getAllPermissions()->pluck('name');
    }
}
