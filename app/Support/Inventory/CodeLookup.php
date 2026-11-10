<?php

namespace App\Support\Inventory;

use App\Models\Item;
use App\Models\Location;

/**
 * Find an item or a location by a scanned or typed code: an item number ("FSR5-004218") or the
 * full code of a location ("EU-014-R03.4"). A hand scanner types the code like a keyboard.
 */
class CodeLookup
{
    public function __construct(private readonly ItemNumbers $numbers) {}

    /**
     * Find the item or location of a code. Item numbers are checked first.
     */
    public function find(string $code): Item|Location|null
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        return $this->numbers->find($code)
            ?? Location::where('full_code', mb_strtoupper($code))->first();
    }

    /**
     * Find a place an item can be moved to: a location, or a box that is not retired.
     */
    public function findPlace(string $code): Item|Location|null
    {
        $found = $this->find($code);

        if ($found instanceof Item && (! $found->is_container || $found->isRetired())) {
            return null;
        }

        return $found;
    }
}
