<?php

namespace App\Http\Controllers;

use App\Actions\Users\UpdateProfile;
use App\Models\PushSubscription;
use App\Notifications\TestNotification;
use App\Rules\KioskPin;
use App\Support\Modules\ModuleRegistry;
use App\Support\Notifications\NotificationEvents;
use App\Support\Notifications\WebPush;
use App\Support\Surface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The own account: name, password, kiosk pin, notifications and the devices with push.
 */
class ProfileController extends Controller
{
    /**
     * Display the profile.
     */
    public function edit(Request $request, NotificationEvents $events): Response
    {
        $user = $request->user();

        return Inertia::render('Profile/Edit', [
            'hasPin' => $user->hasPin(),
            // the one place where the core names the kiosk module: the pin only matters with it
            'showPin' => $this->usesKiosk($request),
            'pinLength' => config('kiosk.pin_length'),
            // the kiosk has neither notifications nor push
            'notifications' => Surface::current() ? null : [
                'events' => $events->table(NotificationEvents::MEMBERS, $user->notificationPreferences(), $user),
                'devices' => $user->pushSubscriptions()->latest('id')->get()->map(fn (PushSubscription $subscription) => [...$subscription->summary(), 'endpoint' => $subscription->endpoint]),
                'push_key' => WebPush::publicKey(),
            ],
        ]);
    }

    /**
     * Save the notification settings: event => channel => on.
     */
    public function notifications(Request $request, NotificationEvents $events): RedirectResponse
    {
        $validated = $request->validate(['events' => ['array'], 'events.*' => ['array'], 'events.*.*' => ['boolean']]);
        $request->user()->forceFill(['notification_preferences' => $events->deviations(NotificationEvents::MEMBERS, $validated['events'] ?? [], $request->user())])->save();

        return back()->with('success', 'Deine Benachrichtigungen wurden gespeichert.');
    }

    /**
     * Store the push subscription of this device (json, called by the browser after the permission).
     */
    public function subscribe(Request $request, WebPush $webPush): JsonResponse
    {
        $subscription = $webPush->subscribe($request->user(), $request);

        return response()->json($subscription->summary());
    }

    /**
     * Remove a device: by its id (list of devices) or by its endpoint (this device switched off).
     */
    public function unsubscribe(Request $request): RedirectResponse|JsonResponse
    {
        $request->validate(['id' => ['nullable', 'integer'], 'endpoint' => ['nullable', 'string', 'max:1024']]);
        $request->user()->pushSubscriptions()
            ->when($request->filled('id'), fn ($query) => $query->whereKey($request->integer('id')), fn ($query) => $query->where('endpoint', (string) $request->input('endpoint')))
            ->delete();

        return $request->expectsJson() ? response()->json(['ok' => true]) : back()->with('success', 'Das Gerät bekommt keine Push-Nachrichten mehr.');
    }

    /**
     * Send a test notification to the own account by mail or push.
     */
    public function test(Request $request): RedirectResponse
    {
        $validated = $request->validate(['channel' => ['required', 'in:mail,push']]);
        $user = $request->user();
        if ($validated['channel'] === 'push' && ! TestNotification::hasPush($user)) {
            throw ValidationException::withMessages(['push' => 'Auf keinem Gerät ist Push eingeschaltet.']);
        }

        $user->notify(new TestNotification($validated['channel']));

        return back()->with('success', $validated['channel'] === 'push'
            ? 'Die Testbenachrichtigung ist unterwegs an '.$user->pushSubscriptions()->count().' Gerät(e).'
            : "Die Testmail ist unterwegs an {$user->email}.");
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
