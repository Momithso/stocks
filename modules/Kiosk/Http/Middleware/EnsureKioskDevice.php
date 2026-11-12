<?php

namespace Modules\Kiosk\Http\Middleware;

use App\Support\Surface;
use Closure;
use Illuminate\Http\Request;
use Modules\Kiosk\Models\KioskDevice;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware "kiosk.device": only paired tablets open the kiosk. The device is available as
 * request attribute "kioskDevice", and the request runs on the surface "kiosk" (see
 * App\Support\Surface): pages get the kiosk layout and build their addresses under /kiosk.
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

        Surface::set($request, [
            'name' => 'kiosk',
            'base' => '/kiosk',
            'route_prefix' => 'kiosk.',
            'layout' => 'Kiosk::KioskLayout',
            'done_route' => 'kiosk.done',
            'manifest' => route('kiosk.manifest', absolute: false),
            'platform' => $device->platform->value,
            'direct_print' => $device->platform->directPrint(),
        ]);

        return $next($request);
    }
}
