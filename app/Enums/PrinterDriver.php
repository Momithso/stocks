<?php

namespace App\Enums;

/**
 * How a printer prints. The frontend has one driver per case (resources/js/labels/drivers), so
 * further drivers (Brother, Zebra) can be added later.
 */
enum PrinterDriver: string
{
    use HasOptions;

    // print dialog of the browser, any printer
    case Paper = 'paper';
    // niimbot label printer: directly by bluetooth or usb (NiimBlueLib), otherwise as image
    case Niimbot = 'niimbot';

    public function label(): string
    {
        return match ($this) {
            self::Paper => 'Normaler Drucker (Papier)',
            self::Niimbot => 'Niimbot',
        };
    }
}
