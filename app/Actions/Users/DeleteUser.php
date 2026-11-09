<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Support\Permissions\GrantCheck;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Delete an account.
 */
class DeleteUser
{
    public function __construct(private readonly GrantCheck $grantCheck) {}

    /**
     * @throws AuthorizationException if the actor may not manage the account
     */
    public function handle(User $actor, User $user): void
    {
        if (! $this->grantCheck->canManageUser($actor, $user)) {
            throw new AuthorizationException('Du kannst dieses Konto nicht löschen.');
        }

        if ($user->isLastAdmin()) {
            throw ValidationException::withMessages(['user' => 'Das letzte aktive Admin-Konto kann nicht gelöscht werden.']);
        }

        $user->delete();
    }
}
