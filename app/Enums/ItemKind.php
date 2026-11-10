<?php

namespace App\Enums;

/**
 * Single piece with its own number, or bulk item with a quantity (cables, tape, cups).
 */
enum ItemKind: string
{
    use HasOptions;

    case Single = 'single';
    case Bulk = 'bulk';

    public function label(): string
    {
        return match ($this) {
            self::Single => 'Einzelstück',
            self::Bulk => 'Mengenartikel',
        };
    }
}
