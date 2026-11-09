<?php

namespace App\Actions\Users;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create the account of an accepted invitation.
 */
class AcceptInvitation
{
    /**
     * Create the account with the roles of the invitation. The roles were checked against the
     * permissions of the inviting person when the invitation was created.
     */
    public function handle(Invitation $invitation, string $firstname, string $lastname, string $password): User
    {
        return DB::transaction(function () use ($invitation, $firstname, $lastname, $password) {
            $user = new User(['firstname' => $firstname, 'lastname' => $lastname, 'email' => $invitation->email]);
            $user->password = $password;
            $user->save();
            $user->syncRoles($invitation->roles);

            $invitation->update(['accepted_at' => now()]);

            return $user;
        });
    }
}
