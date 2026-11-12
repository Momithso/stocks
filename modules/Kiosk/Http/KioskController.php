<?php

namespace Modules\Kiosk\Http;

use App\Http\Controllers\Controller;
use App\Http\Controllers\WebAppController;
use App\Models\User;
use App\Support\Branding;
use App\Support\Extensions\Navigation;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Kiosk\Actions\UnlockKiosk;
use Modules\Kiosk\Models\KioskDevice;
use Modules\Kiosk\Support\KioskSession;

/**
 * The kiosk on paired tablets: lock screen with the accounts that may use the kiosk, unlock with
 * the pin, home page with the tiles of the active modules, remaining time and its own web app.
 */
class KioskController extends Controller
{
    public function __construct(private readonly KioskSession $kioskSession) {}

    /**
     * Pair a tablet: the id and token are stored in a long living cookie, so the tablet opens the
     * kiosk without the token in the url. A normal login of the browser ends, so nobody stays
     * logged in outside the kiosk.
     */
    public function pair(Request $request, KioskDevice $device): RedirectResponse
    {
        abort_unless($device->isTokenValid((string) $request->query('token')), 404);

        // a normal login in this browser would stay usable beside the kiosk: end it
        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $cookie = Cookie::make(KioskDevice::COOKIE_NAME, $device->cookieValue(), KioskDevice::COOKIE_LIFETIME, '/kiosk');

        return redirect()->route('kiosk.lock')
            ->withCookie($cookie)
            ->with('success', "Dieses Gerät ist jetzt als Kiosk {$device->name} freigeschaltet.");
    }

    /**
     * Display the lock screen. Opening it always locks the kiosk.
     */
    public function lock(Request $request): Response
    {
        $this->kioskSession->lock();

        return Inertia::render('Kiosk::Lock', [
            'people' => User::permission('kiosk.use')
                ->where('is_disabled', false)
                ->orderBy('firstname')
                ->orderBy('lastname')
                ->get(['id', 'firstname', 'lastname', 'pin'])
                ->map(fn (User $user) => [...$user->only(['id', 'firstname', 'lastname']), 'has_pin' => $user->hasPin()]),
            'pinLength' => config('kiosk.pin_length'),
            'installHint' => $request->attributes->get('kioskDevice')->platform->installHint(),
        ]);
    }

    /**
     * Unlock the kiosk with the pin. A new session id, so a session id seen before the unlock is
     * worthless afterwards.
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
        $request->session()->regenerate();

        return redirect()->route('kiosk.home');
    }

    /**
     * Display the home page of the unlocked kiosk.
     */
    public function home(Request $request, Navigation $navigation): Response
    {
        return Inertia::render('Kiosk::Home', [
            'tiles' => $navigation->kioskTilesForUser($request->user()),
        ]);
    }

    /**
     * Keep the kiosk unlocked: the middleware "kiosk" already refreshed the activity (json).
     */
    public function extend(): JsonResponse
    {
        return response()->json(['expires_in' => $this->kioskSession->remaining()]);
    }

    /**
     * After a finished task (see App\Support\Surface::finish): lock the kiosk or go to the home
     * page, as set in the settings. The message of the task goes along.
     */
    public function done(Request $request, Settings $settings): RedirectResponse
    {
        $messages = array_filter($request->session()->only(['success', 'warning', 'error']));

        if ($settings->get('kiosk.lock_after_task')) {
            $this->kioskSession->lock();

            return redirect()->route('kiosk.lock')->with($messages);
        }

        return redirect()->route('kiosk.home')->with($messages);
    }

    /**
     * Web app of the kiosk: an installed tablet starts on the lock screen, not on the login.
     */
    public function manifest(Settings $settings, Branding $branding): JsonResponse
    {
        $version = pathinfo((string) $branding->path('logo'), PATHINFO_FILENAME) ?: 'default';

        return response()->json([
            'name' => $settings->get('organization.short_name').' Kiosk',
            'short_name' => 'Kiosk',
            'start_url' => '/kiosk',
            'scope' => '/kiosk',
            'display' => 'fullscreen',
            'display_override' => ['fullscreen', 'standalone'],
            'orientation' => 'any',
            'background_color' => '#ffffff',
            'theme_color' => $settings->get('organization.accent_color'),
            'icons' => array_map(fn (int $size) => [
                'src' => route('web-app.icon', ['size' => $size, 'v' => $version], absolute: false),
                'sizes' => "{$size}x{$size}",
                'type' => 'image/png',
                'purpose' => 'any',
            ], WebAppController::ICON_SIZES),
        ], headers: ['Content-Type' => 'application/manifest+json']);
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
