<?php

namespace App\Actions\Users;

use App\Models\User;

/**
 * Change the own name, password or kiosk pin.
 */
class UpdateProfile
{
    /**
     * Only given values are changed. The current password was checked by the request.
     */
    public function handle(User $user, string $firstname, string $lastname, ?string $password = null, ?string $pin = null): void
    {
        $user->fill(['firstname' => $firstname, 'lastname' => $lastname]);

        if ($password !== null) {
            $user->password = $password;
        }
        if ($pin !== null) {
            $user->pin = $pin;
        }

        $user->save();
    }
}
