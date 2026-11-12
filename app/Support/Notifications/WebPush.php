<?php

namespace App\Support\Notifications;

use App\Models\PushSubscription;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use NotificationChannels\WebPush\HasPushSubscriptions;

/**
 * Web push of Stocks: whether it is on (keys in the environment), which push services may receive
 * subscriptions, and storing the subscription of a device. See documentation/NOTIFICATIONS.md.
 */
class WebPush
{
    /**
     * Hosts of the push services of browsers. Endpoints elsewhere are refused, so nobody can make
     * Stocks send requests to any address.
     *
     * @var list<string>
     */
    public const HOSTS = ['*.push.apple.com', 'fcm.googleapis.com', 'updates.push.services.mozilla.com', '*.notify.windows.com'];

    /**
     * Subscriptions per account or loan at most.
     */
    public const MAX_PER_OWNER = 10;

    /**
     * Check if push is on: both keys are set.
     */
    public static function enabled(): bool
    {
        return filled(config('webpush.vapid.public_key')) && filled(config('webpush.vapid.private_key'));
    }

    /**
     * Public key for the browser, null while push is off.
     */
    public static function publicKey(): ?string
    {
        return self::enabled() ? config('webpush.vapid.public_key') : null;
    }

    /**
     * Check if an endpoint belongs to a known push service (https only).
     */
    public static function isAllowedEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])) {
            return false;
        }
        $host = strtolower($parts['host']);
        foreach (self::HOSTS as $pattern) {
            if ($host === ltrim($pattern, '*.') || (str_starts_with($pattern, '*.') && str_ends_with($host, substr($pattern, 1)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Store the subscription of the requesting device for an account or a loan.
     *
     * @param  Model&HasPushSubscriptions  $owner
     *
     * @throws ValidationException
     */
    public function subscribe(Model $owner, Request $request): PushSubscription
    {
        if (! self::enabled()) {
            throw ValidationException::withMessages(['push' => 'Push ist auf diesem Server nicht eingerichtet.']);
        }
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:1024'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'in:aesgcm,aes128gcm'],
        ]);
        if (! self::isAllowedEndpoint($validated['endpoint'])) {
            throw ValidationException::withMessages(['push' => 'Dieser Push-Dienst ist nicht bekannt.']);
        }
        $known = $owner->pushSubscriptions()->where('endpoint', $validated['endpoint'])->exists();
        if (! $known && $owner->pushSubscriptions()->count() >= self::MAX_PER_OWNER) {
            throw ValidationException::withMessages(['push' => 'Es sind schon '.self::MAX_PER_OWNER.' Geräte eingetragen. Entferne zuerst eines.']);
        }

        /** @var PushSubscription $subscription */
        $subscription = $owner->updatePushSubscription($validated['endpoint'], $validated['keys']['p256dh'], $validated['keys']['auth'], $validated['content_encoding'] ?? 'aes128gcm');
        $subscription->forceFill(['device' => self::deviceName((string) $request->userAgent())])->save();

        return $subscription;
    }

    /**
     * Short name of a device from its user agent, e.g. "iPhone" or "Firefox auf Windows".
     */
    public static function deviceName(string $userAgent): string
    {
        $system = match (true) {
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS') => 'Mac',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };
        if (in_array($system, ['iPhone', 'iPad'], true)) {
            return $system;
        }
        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($userAgent, 'Firefox') => 'Firefox',
            str_contains($userAgent, 'Chrome') => 'Chrome',
            str_contains($userAgent, 'Safari') => 'Safari',
            default => 'Browser',
        };

        return $system ? "{$browser} auf {$system}" : $browser;
    }
}
