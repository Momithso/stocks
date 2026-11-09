<?php

namespace App\Actions\Users;

use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Permissions\GrantCheck;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Change the roles of an account and disable or enable it.
 */
class UpdateUser
{
    public function __construct(private readonly GrantCheck $grantCheck) {}

    /**
     * @param  list<int>  $roleIds  all roles the account should have afterwards
     *
     * @throws AuthorizationException if the actor may not manage the account
     */
    public function handle(User $actor, User $user, array $roleIds, bool $isDisabled): void
    {
        if (! $this->grantCheck->canManageUser($actor, $user)) {
            throw new AuthorizationException('Du kannst dieses Konto nicht bearbeiten.');
        }

        $roles = Role::with('permissions')->whereIn('id', $roleIds)->get();
        $oldRoles = $user->roles()->with('permissions')->get();

        // roles that are given or taken away must be grantable by the actor
        $changedRoles = $roles->diff($oldRoles)->merge($oldRoles->diff($roles));
        if ($changedRoles->isNotEmpty() && ! $actor->can('roles.manage')) {
            throw new AuthorizationException('Du kannst keine Rollen zuweisen.');
        }
        foreach ($changedRoles as $role) {
            if (! $this->grantCheck->canGrantRole($actor, $role)) {
                throw ValidationException::withMessages(['roles' => "Du kannst die Rolle {$role->name} nicht vergeben oder entziehen."]);
            }
        }

        $losesAdmin = $isDisabled || ! $roles->contains('name', Role::admin());
        if ($losesAdmin && $user->isLastAdmin()) {
            throw ValidationException::withMessages(['roles' => 'Das letzte aktive Admin-Konto muss Admin bleiben.']);
        }

        DB::transaction(function () use ($user, $roles, $oldRoles, $changedRoles, $isDisabled) {
            $user->update(['is_disabled' => $isDisabled]);

            if ($changedRoles->isNotEmpty()) {
                $user->syncRoles($roles);
                AuditRecorder::record($user, 'rolesUpdated', ['roles' => $oldRoles->pluck('name')->all()], ['roles' => $roles->pluck('name')->all()]);
                // role changes do not change the user itself, touched for the live update of the user table
                $user->touch();
            }
        });
    }
}
