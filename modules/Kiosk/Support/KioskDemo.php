<?php

namespace Modules\Kiosk\Support;

use App\Support\Demo\DemoContext;
use App\Support\Demo\DemoPart;
use Modules\Kiosk\Enums\KioskPlatform;
use Modules\Kiosk\Models\KioskDevice;

/**
 * Demo data of the kiosk module: two paired tablets, one per platform that prints directly, so
 * both kinds of kiosk can be tried (documentation/DEMO.md). Their pairing urls are on the page
 * Verwaltung › Kiosk-Geräte.
 */
class KioskDemo implements DemoPart
{
    /**
     * Name and platform of the demo devices.
     */
    private const DEVICES = [
        'Lager-Tablet' => KioskPlatform::Android,
        'iPad Ausgabe' => KioskPlatform::Ipados,
    ];

    private const TOKEN_CHARACTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    public function run(DemoContext $demo): void
    {
        foreach (self::DEVICES as $name => $platform) {
            // the token comes from the seed, so the pairing urls stay the same with the same seed
            KioskDevice::create([
                'name' => $name,
                'platform' => $platform,
                'token' => $demo->random->getBytesFromString(self::TOKEN_CHARACTERS, 64),
            ]);
        }
    }
}
