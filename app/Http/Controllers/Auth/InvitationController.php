<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Users\AcceptInvitation;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accept an invitation and create the account.
 */
class InvitationController extends Controller
{
    /**
     * Display the form to create the account.
     */
    public function show(string $token): Response
    {
        $invitation = Invitation::findOpenByToken($token) ?? abort(404, 'Die Einladung ist ungültig, abgelaufen oder wurde schon angenommen.');

        return Inertia::render('Auth/AcceptInvitation', [
            'token' => $token,
            'email' => $invitation->email,
        ]);
    }

    /**
     * Create the account and log in.
     */
    public function store(Request $request, string $token, AcceptInvitation $acceptInvitation): RedirectResponse
    {
        $invitation = Invitation::findOpenByToken($token) ?? abort(404, 'Die Einladung ist ungültig, abgelaufen oder wurde schon angenommen.');

        $validated = $request->validate([
            'firstname' => ['required', 'string', 'max:64'],
            'lastname' => ['required', 'string', 'max:64'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = $acceptInvitation->handle($invitation, $validated['firstname'], $validated['lastname'], $validated['password']);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', 'Willkommen bei Stocks!');
    }
}
