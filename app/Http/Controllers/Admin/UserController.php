<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Users\DeleteUser;
use App\Actions\Users\InviteUser;
use App\Actions\Users\ResetUserPin;
use App\Actions\Users\UpdateUser;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions\GrantCheck;
use App\Support\Table\UsersTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Account management: list, invite, change roles, disable, delete.
 */
class UserController extends Controller
{
    /**
     * Display the accounts and the open invitations.
     */
    public function index(Request $request, GrantCheck $grantCheck): Response|JsonResponse
    {
        $table = new UsersTable($request);
        if ($table->wantsChanges()) {
            return $table->changesResponse();
        }

        $actor = $request->user();

        return Inertia::render('Admin/Users/Index', [
            'users' => $table->scroll(),
            'roles' => fn () => Role::with('permissions')->orderBy('name')->get()->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                // roles the actor can not grant are shown, but can not be selected
                'grantable' => $grantCheck->canGrantRole($actor, $role),
            ]),
            'invitations' => fn () => $actor->can('users.invite')
                ? Invitation::open()->with('roles:id,name', 'invitedBy:id,firstname,lastname')->latest()->get()
                : [],
        ]);
    }

    /**
     * Invite a person.
     */
    public function invite(Request $request, InviteUser $inviteUser): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'roles' => ['array'],
            'roles.*' => ['integer'],
        ]);

        $inviteUser->handle($request->user(), $validated['email'], $validated['roles'] ?? []);

        return back()->with('success', "Die Einladung an {$validated['email']} ist unterwegs.");
    }

    /**
     * Withdraw an open invitation.
     */
    public function destroyInvitation(Invitation $invitation): RedirectResponse
    {
        $invitation->delete();

        return back()->with('success', 'Die Einladung wurde zurückgezogen.');
    }

    /**
     * Change the roles of an account or disable it.
     */
    public function update(Request $request, User $user, UpdateUser $updateUser): RedirectResponse
    {
        $validated = $request->validate([
            'roles' => ['array'],
            'roles.*' => ['integer'],
            'is_disabled' => ['required', 'boolean'],
        ]);

        $updateUser->handle($request->user(), $user, $validated['roles'] ?? [], $validated['is_disabled']);

        return back()->with('success', "Das Konto von {$user->name} wurde gespeichert.");
    }

    /**
     * Remove the kiosk pin of an account.
     */
    public function resetPin(Request $request, User $user, ResetUserPin $resetUserPin): RedirectResponse
    {
        $resetUserPin->handle($request->user(), $user);

        return back()->with('success', "Die PIN von {$user->name} wurde zurückgesetzt.");
    }

    /**
     * Delete an account.
     */
    public function destroy(Request $request, User $user, DeleteUser $deleteUser): RedirectResponse
    {
        $deleteUser->handle($request->user(), $user);

        return back()->with('success', "Das Konto von {$user->name} wurde gelöscht.");
    }
}
