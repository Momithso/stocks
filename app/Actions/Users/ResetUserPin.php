<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Permissions\GrantCheck;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Remove the kiosk pin of an account. The person sets a new one in the profile. Nobody can see
 * or set the pin of another person.
 */
class ResetUserPin
{
    public function __construct(private readonly GrantCheck $grantCheck) {}

    /**
     * @throws AuthorizationException if the actor may not manage the account
     */
    public function handle(User $actor, User $user): void
    {
        if (! $this->grantCheck->canManageUser($actor, $user)) {
            throw new AuthorizationException('Du kannst die PIN dieses Kontos nicht zurücksetzen.');
        }

        $user->forceFill(['pin' => null])->save();
        AuditRecorder::record($user, 'pinReset');
    }
}
