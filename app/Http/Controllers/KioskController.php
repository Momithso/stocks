<?php

namespace App\Http\Controllers;

use App\Actions\Kiosk\UnlockKiosk;
use App\Models\KioskDevice;
use App\Models\User;
use App\Support\Extensions\Navigation;
use App\Support\Kiosk\KioskSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The kiosk on paired tablets: lock screen with the accounts that may use the kiosk, unlock with
 * the pin, home page with the tiles of the active modules.
 */
class KioskController extends Controller
{
    public function __construct(private readonly KioskSession $kioskSession) {}

    /**
     * Pair a tablet: the id and token are stored in a long living cookie, so the tablet opens the
     * kiosk without the token in the url.
     */
    public function pair(Request $request, KioskDevice $device): RedirectResponse
    {
        abort_unless($device->isTokenValid((string) $request->query('token')), 404);

        $cookie = Cookie::make(KioskDevice::COOKIE_NAME, $device->cookieValue(), KioskDevice::COOKIE_LIFETIME, '/kiosk');

        return redirect()->route('kiosk.lock')
            ->withCookie($cookie)
            ->with('success', "Dieses Gerät ist jetzt als Kiosk {$device->name} freigeschaltet.");
    }

    /**
     * Display the lock screen. Opening it always locks the kiosk.
     */
    public function lock(): Response
    {
        $this->kioskSession->lock();

        return Inertia::render('Kiosk/Lock', [
            'people' => User::permission('kiosk.use')
                ->where('is_disabled', false)
                ->orderBy('firstname')
                ->orderBy('lastname')
                ->get(['id', 'firstname', 'lastname', 'pin'])
                ->map(fn (User $user) => [...$user->only(['id', 'firstname', 'lastname']), 'has_pin' => $user->hasPin()]),
            'pinLength' => config('kiosk.pin_length'),
        ]);
    }

    /**
     * Unlock the kiosk with the pin.
     */
    public function unlock(Request $request, UnlockKiosk $unlockKiosk): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'pin' => ['required', 'string'],
        ]);

        $user = User::permission('kiosk.use')
            ->where('is_disabled', false)
            ->findOrFail($validated['user_id']);

        $unlockKiosk->handle($request->attributes->get('kioskDevice'), $user, $validated['pin']);

        return redirect()->route('kiosk.home');
    }

    /**
     * Display the home page of the unlocked kiosk.
     */
    public function home(Request $request, Navigation $navigation): Response
    {
        $user = $request->attributes->get('kioskUser');

        return Inertia::render('Kiosk/Home', [
            'person' => $user->only(['id', 'firstname', 'lastname']),
            'tiles' => $navigation->kioskTilesForUser($user),
            'sessionTimeout' => config('kiosk.session_timeout'),
        ]);
    }

    /**
     * Lock the kiosk again.
     */
    public function logout(): RedirectResponse
    {
        $this->kioskSession->lock();

        return redirect()->route('kiosk.lock');
    }
}
