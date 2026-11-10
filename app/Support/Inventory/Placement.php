<?php

namespace App\Support\Inventory;

use App\Enums\LocationType;
use App\Models\Item;
use App\Models\Location;
use Illuminate\Validation\ValidationException;

/**
 * Rules for putting an item somewhere (current place or home).
 */
class Placement
{
    /**
     * Check that the item may lie at the place, otherwise throw a validation error for the field.
     *
     * Items lie in a room, a shelf or a compartment, never directly at an address. A box can only
     * hold items if it is marked as box and not retired, and a box can not be put into itself or
     * into a box of its own contents.
     *
     * @throws ValidationException
     */
    public static function check(Item $item, Location|Item|null $place, string $field): void
    {
        if ($place instanceof Location && $place->type === LocationType::Address) {
            throw ValidationException::withMessages([$field => "{$place->full_code} ist eine Adresse. Gegenstände liegen in einem Raum, Lagerplatz oder Fach."]);
        }
        if (! $place instanceof Item) {
            return;
        }

        $message = match (true) {
            ! $place->is_container => "{$place->number} ist keine Box.",
            $place->isRetired() => "{$place->number} ist ausgemustert.",
            $item->exists && $place->is($item) => 'Eine Box kann nicht in sich selbst liegen.',
            $item->exists && $place->isInside($item) => "{$place->number} liegt in {$item->number}. Eine Box kann nicht in ihren eigenen Inhalt.",
            default => null,
        };

        if ($message !== null) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
