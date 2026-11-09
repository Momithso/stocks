<?php

namespace App\Actions\Users;

use App\Models\Invitation;
use App\Models\Role;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Support\Permissions\GrantCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Invite a person to create an account. Accounts are never created any other way.
 */
class InviteUser
{
    public function __construct(private readonly GrantCheck $grantCheck) {}

    /**
     * Create the invitation and send the link by mail. An older open invitation of the same
     * address is replaced, so only the newest link works.
     *
     * @param  list<int>  $roleIds  roles the account gets, the actor must be able to grant all of them
     */
    public function handle(User $actor, string $email, array $roleIds): Invitation
    {
        $email = mb_strtolower(trim($email));

        if (User::where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'Für diese Adresse gibt es schon ein Konto.']);
        }

        $roles = Role::with('permissions')->whereIn('id', $roleIds)->get();
        if ($roles->isNotEmpty() && ! $actor->can('roles.manage')) {
            throw ValidationException::withMessages(['roles' => 'Du kannst keine Rollen zuweisen. Lade ohne Rolle ein.']);
        }
        foreach ($roles as $role) {
            if (! $this->grantCheck->canGrantRole($actor, $role)) {
                throw ValidationException::withMessages(['roles' => "Du kannst die Rolle {$role->name} nicht vergeben, weil dir Rechte dieser Rolle fehlen."]);
            }
        }

        $token = Str::random(48);

        $invitation = DB::transaction(function () use ($actor, $email, $roles, $token) {
            Invitation::open()->where('email', $email)->get()->each->delete();

            $invitation = Invitation::create([
                'email' => $email,
                'token_hash' => hash('sha256', $token),
                'invited_by_id' => $actor->id,
                'expires_at' => now()->addDays(config('stocks.invitation_lifetime_days')),
            ]);
            $invitation->roles()->sync($roles->pluck('id'));

            return $invitation;
        });

        Notification::route('mail', $email)->notify(new InvitationNotification($token, $actor->name));

        return $invitation;
    }
}
