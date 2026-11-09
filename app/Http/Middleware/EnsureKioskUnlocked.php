<?php

namespace App\Http\Middleware;

use App\Support\Kiosk\KioskSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware "kiosk": the kiosk must be unlocked with a pin. Use it after "kiosk.device". The
 * person that unlocked it is available as request attribute "kioskUser".
 */
class EnsureKioskUnlocked
{
    public function __construct(private readonly KioskSession $kioskSession) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->kioskSession->user();

        if (! $user) {
            return redirect()->route('kiosk.lock')->with('warning', 'Bitte entsperre den Kiosk mit deiner PIN.');
        }

        $request->attributes->set('kioskUser', $user);

        return $next($request);
    }
}
