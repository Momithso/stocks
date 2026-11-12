<?php

namespace Modules\Kiosk\Http;

use App\Http\Controllers\Controller;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Kiosk\Enums\KioskPlatform;
use Modules\Kiosk\Models\KioskDevice;

/**
 * Pair tablets as kiosk, set their platform, lock them out again, and the settings of the kiosk
 * session. Plain create, change and delete without further rules, so no actions are used here.
 */
class DeviceController extends Controller
{
    /**
     * Display the devices with their pairing url and qr code, and the settings.
     */
    public function index(Settings $settings): Response
    {
        return Inertia::render('Kiosk::Devices', [
            'devices' => KioskDevice::orderBy('name')->get()->map(fn (KioskDevice $device) => [
                'id' => $device->id,
                'name' => $device->name,
                'platform' => $device->platform->value,
                'platform_label' => $device->platform->label(),
                'last_used_at' => $device->last_used_at?->toIso8601String(),
                'pairing_url' => $device->pairingUrl(),
                'pairing_qr_code' => $device->pairingQrCodeSvg(),
            ]),
            'platforms' => KioskPlatform::options(),
            'settings' => [
                'session_timeout' => (int) $settings->get('kiosk.session_timeout'),
                'lock_after_task' => (bool) $settings->get('kiosk.lock_after_task'),
            ],
        ]);
    }

    /**
     * Create a device.
     */
    public function store(Request $request): RedirectResponse
    {
        KioskDevice::create([...$this->validated($request), 'token' => KioskDevice::generateToken()]);

        return back()->with('success', 'Das Gerät wurde angelegt. Öffne die Kopplungs-Adresse jetzt auf dem Tablet.');
    }

    /**
     * Rename a device or change its platform; it stays paired.
     */
    public function update(Request $request, KioskDevice $device): RedirectResponse
    {
        $device->update($this->validated($request));

        return back()->with('success', "{$device->name} wurde gespeichert.");
    }

    /**
     * Create a new token: the tablet is locked out and must be paired again.
     */
    public function regenerateToken(KioskDevice $device): RedirectResponse
    {
        $device->update(['token' => KioskDevice::generateToken()]);

        return back()->with('success', "{$device->name} ist gesperrt und muss neu gekoppelt werden.");
    }

    /**
     * Delete a device, the tablet is locked out immediately.
     */
    public function destroy(KioskDevice $device): RedirectResponse
    {
        $device->delete();

        return back()->with('success', "{$device->name} wurde gelöscht.");
    }

    /**
     * Save the settings of the kiosk session: time without input and lock after every task.
     */
    public function settings(Request $request, Settings $settings): RedirectResponse
    {
        $validated = $request->validate([
            'session_timeout' => ['required', 'integer', 'between:30,3600'],
            'lock_after_task' => ['required', 'boolean'],
        ]);

        $settings->set([
            'kiosk.session_timeout' => (int) $validated['session_timeout'],
            'kiosk.lock_after_task' => (bool) $validated['lock_after_task'],
        ]);

        return back()->with('success', 'Die Einstellungen des Kiosks wurden gespeichert.');
    }

    /**
     * @return array{name: string, platform: string}
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'platform' => ['nullable', Rule::enum(KioskPlatform::class)],
        ]);

        // without a platform the browser decides, as before platforms existed
        return ['name' => $validated['name'], 'platform' => $validated['platform'] ?? KioskPlatform::Other->value];
    }
}
