<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KioskDevice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pair tablets as kiosk and lock them out again. Plain create and delete without further rules,
 * so no actions are used here.
 */
class KioskDeviceController extends Controller
{
    /**
     * Display the devices with their pairing url and qr code.
     */
    public function index(): Response
    {
        return Inertia::render('Admin/KioskDevices', [
            'devices' => KioskDevice::orderBy('name')->get()->map(fn (KioskDevice $device) => [
                'id' => $device->id,
                'name' => $device->name,
                'last_used_at' => $device->last_used_at?->toIso8601String(),
                'pairing_url' => $device->pairingUrl(),
                'pairing_qr_code' => $device->pairingQrCodeSvg(),
            ]),
        ]);
    }

    /**
     * Create a device.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:50']]);
        KioskDevice::create([...$validated, 'token' => KioskDevice::generateToken()]);

        return back()->with('success', 'Das Gerät wurde angelegt. Öffne die Kopplungs-Adresse jetzt auf dem Tablet.');
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
}
