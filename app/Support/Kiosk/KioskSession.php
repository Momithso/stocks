<?php

namespace App\Support\Kiosk;

use App\Models\User;
use Illuminate\Support\Facades\Session;

/**
 * The person that unlocked the kiosk with the PIN.
 *
 * The kiosk session is separate from the normal login: a person working at the kiosk is not
 * logged in to the rest of Stocks. It ends after config('kiosk.session_timeout') seconds without
 * activity, on logout and when the person is disabled or loses the permission "kiosk.use".
 */
class KioskSession
{
    /**
     * Session key of the unlocked person.
     *
     * @var string
     */
    private const SESSION_KEY = 'kiosk';

    /**
     * Unlock the kiosk for a person.
     */
    public function unlock(User $user): void
    {
        Session::put(self::SESSION_KEY, ['user_id' => $user->id, 'last_activity' => now()->timestamp]);
    }

    /**
     * Lock the kiosk again.
     */
    public function lock(): void
    {
        Session::forget(self::SESSION_KEY);
    }

    /**
     * Get the person that unlocked the kiosk and refresh the activity, null if the kiosk is locked.
     */
    public function user(): ?User
    {
        $session = Session::get(self::SESSION_KEY);

        if (! is_array($session) || ($session['last_activity'] ?? 0) + config('kiosk.session_timeout') < now()->timestamp) {
            $this->lock();

            return null;
        }

        $user = User::find($session['user_id'] ?? null);
        if (! $user || $user->is_disabled || ! $user->can('kiosk.use')) {
            $this->lock();

            return null;
        }

        Session::put(self::SESSION_KEY.'.last_activity', now()->timestamp);

        return $user;
    }
}
