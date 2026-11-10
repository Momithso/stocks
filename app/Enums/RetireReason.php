<?php

namespace App\Enums;

/**
 * Why an item (or a part of a bulk item) left the inventory.
 */
enum RetireReason: string
{
    use HasOptions;

    case Consumed = 'consumed';
    case Defective = 'defective';
    case Lost = 'lost';
    case Sold = 'sold';
    case Disposed = 'disposed';
    case Other = 'other';
    // set by App\Actions\Inventory\MergeItem, not offered for selection
    case Merged = 'merged';

    public function label(): string
    {
        return match ($this) {
            self::Consumed => 'verbraucht',
            self::Defective => 'defekt',
            self::Lost => 'verloren',
            self::Sold => 'verkauft',
            self::Disposed => 'entsorgt',
            self::Other => 'sonstiges',
            self::Merged => 'zurückgebucht',
        };
    }
}
