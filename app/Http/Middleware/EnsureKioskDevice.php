<?php

namespace App\Http\Middleware;

use App\Models\KioskDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware "kiosk.device": only paired tablets open the kiosk. The device is available as
 * request attribute "kioskDevice".
 */
class EnsureKioskDevice
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $device = KioskDevice::findByCookie($request->cookie(KioskDevice::COOKIE_NAME));

        abort_unless($device, 401, 'Dieses Gerät ist nicht als Kiosk freigeschaltet.');

        $device->touchLastUsed();
        $request->attributes->set('kioskDevice', $device);

        return $next($request);
    }
}
