<?php

namespace Modules\Kiosk\Enums;

use App\Enums\HasOptions;

/**
 * Platform of a kiosk tablet, set per device. It only limits what the pages offer; "other" leaves
 * everything to the browser, as before platforms existed.
 */
enum KioskPlatform: string
{
    use HasOptions;

    case Android = 'android';
    case Ipados = 'ipados';
    case Desktop = 'desktop';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Android => 'Android-Tablet',
            self::Ipados => 'iPad',
            self::Desktop => 'PC oder Mac',
            self::Other => 'Anderes Gerät',
        };
    }

    /**
     * Can the browser print directly to a label printer (Bluetooth, USB)? Chrome and Edge can on
     * Android and on computers, Safari on the iPad can not; null lets the browser decide.
     */
    public function directPrint(): ?bool
    {
        return match ($this) {
            self::Android, self::Desktop => true,
            self::Ipados => false,
            self::Other => null,
        };
    }

    /**
     * Short hint on the lock screen how to run the kiosk in full screen.
     */
    public function installHint(): string
    {
        return match ($this) {
            self::Android => 'In Chrome: Menü › „Zum Startbildschirm hinzufügen“. Dann startet der Kiosk im Vollbild.',
            self::Ipados => 'In Safari: Teilen › „Zum Home-Bildschirm“. Dann startet der Kiosk im Vollbild. Etiketten druckt ihr hier als Bild über die Niimbot-App.',
            self::Desktop => 'In Chrome oder Edge: Menü › „Als App installieren“ oder F11 für Vollbild.',
            self::Other => 'Füge den Kiosk im Browser zum Startbildschirm hinzu, dann startet er im Vollbild.',
        };
    }
}
