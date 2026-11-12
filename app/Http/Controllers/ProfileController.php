<?php

namespace App\Http\Controllers;

use App\Actions\Users\UpdateProfile;
use App\Rules\KioskPin;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The own account: name, password and kiosk pin.
 */
class ProfileController extends Controller
{
    /**
     * Display the profile.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'hasPin' => $request->user()->hasPin(),
            // the one place where the core names the kiosk module: the pin only matters with it
            'showPin' => $this->usesKiosk($request),
            'pinLength' => config('kiosk.pin_length'),
        ]);
    }

    /**
     * Save the profile. A new password needs the current one.
     */
    public function update(Request $request, UpdateProfile $updateProfile): RedirectResponse
    {
        $validated = $request->validate([
            'firstname' => ['required', 'string', 'max:64'],
            'lastname' => ['required', 'string', 'max:64'],
            'current_password' => ['nullable', 'required_with:password', 'current_password'],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'pin' => ['nullable', 'confirmed', new KioskPin],
        ]);

        $updateProfile->handle(
            $request->user(),
            $validated['firstname'],
            $validated['lastname'],
            $validated['password'] ?? null,
            $validated['pin'] ?? null,
        );

        return back()->with('success', 'Dein Profil wurde gespeichert.');
    }

    /**
     * Check if the profile shows the kiosk pin: the module "kiosk" is on and the person may use it.
     */
    private function usesKiosk(Request $request): bool
    {
        return app(ModuleRegistry::class)->isActive('kiosk') && $request->user()->can('kiosk.use');
    }
}
