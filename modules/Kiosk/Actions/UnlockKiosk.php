<?php

namespace Modules\Kiosk\Actions;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Modules\Kiosk\Models\KioskDevice;
use Modules\Kiosk\Support\KioskSession;

/**
 * Unlock the kiosk for a person with the pin.
 */
class UnlockKiosk
{
    public function __construct(private readonly KioskSession $kioskSession) {}

    /**
     * Wrong pins are limited per person and per device: all tablets can share one IP address,
     * and the limit per device stops trying common pins for many persons.
     */
    public function handle(KioskDevice $device, User $user, string $pin): void
    {
        if (! $user->hasPin()) {
            throw ValidationException::withMessages(['pin' => 'Du hast noch keine PIN. Lege sie in deinem Profil fest.']);
        }

        $deviceKey = 'kiosk-device-pin:'.$device->id;
        if (RateLimiter::tooManyAttempts($deviceKey, config('kiosk.device_max_failed_pins'))) {
            throw ValidationException::withMessages(['pin' => 'Zu viele falsche PINs an diesem Gerät. Das Gerät ist noch '.ceil(RateLimiter::availableIn($deviceKey) / 60).' Minuten gesperrt.']);
        }

        $userKey = 'kiosk-pin:'.$user->id;
        if (RateLimiter::tooManyAttempts($userKey, config('kiosk.pin_max_attempts'))) {
            throw ValidationException::withMessages(['pin' => 'Zu viele falsche Versuche. Bitte warte '.RateLimiter::availableIn($userKey).' Sekunden.']);
        }

        if (! $user->isPinValid($pin)) {
            RateLimiter::hit($userKey, 60);
            RateLimiter::hit($deviceKey, config('kiosk.device_lock_seconds'));

            throw ValidationException::withMessages(['pin' => 'Die PIN ist falsch.']);
        }

        RateLimiter::clear($userKey);
        $this->kioskSession->unlock($user);
    }
}
