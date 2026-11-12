<?php

namespace Modules\Kiosk\Http\Middleware;

use App\Support\Settings;
use App\Support\Surface;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Kiosk\Support\KioskSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware "kiosk": the kiosk must be unlocked with a pin. Use it after "kiosk.device".
 *
 * The person that unlocked it is logged in for this request only (Auth::setUser writes nothing to
 * the session), so controllers, "can:" and the audit log see them as $request->user() like on the
 * pc. The normal login of the browser stays untouched, and the pin never opens the rest of Stocks.
 * The person is also available as request attribute "kioskUser".
 */
class EnsureKioskUnlocked
{
    public function __construct(private readonly KioskSession $kioskSession, private readonly Settings $settings) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->kioskSession->user();

        if (! $user) {
            // requests of the timer and other json requests get no redirect
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => 'Der Kiosk ist gesperrt.'], 401);
            }

            return redirect()->route('kiosk.lock')->with('warning', 'Bitte entsperre den Kiosk mit deiner PIN.');
        }

        Auth::setUser($user);
        $request->attributes->set('kioskUser', $user);

        Surface::set($request, [
            'person' => $user->only(['id', 'firstname', 'lastname']),
            'expires_in' => $this->kioskSession->remaining(),
            'lock_after_task' => (bool) $this->settings->get('kiosk.lock_after_task'),
        ]);

        try {
            return $next($request);
        } finally {
            // the response is built by now; nothing of the person stays for the next request
            // (Octane, tests), even though Octane flushes the guards itself
            Auth::forgetUser();
        }
    }
}
