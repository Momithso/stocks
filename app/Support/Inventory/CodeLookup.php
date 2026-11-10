<?php

namespace App\Support\Inventory;

use App\Models\Item;
use App\Models\Location;
use App\Support\Labels\ShortLink;

/**
 * Find an item or a location by a scanned or typed code: an item number ("FSR5-004218") or the
 * full code of a location ("EU-014-R03.4"). A hand scanner types the code like a keyboard.
 *
 * The code of a label is a short link ("https://stocks.example/s/FSR5-004218"); scanned in Stocks it
 * counts like the code at its end, whatever the address (App\Support\Labels\ShortLink).
 */
class CodeLookup
{
    public function __construct(private readonly ItemNumbers $numbers) {}

    /**
     * Find the item or location of a code. Item numbers are checked first.
     */
    public function find(string $code): Item|Location|null
    {
        $code = ShortLink::codeOf($code);
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

    /**
     * Message for a code that was not found, with a hint at a mistyped check digit.
     */
    public function notFoundMessage(string $code, bool $place = false): string
    {
        $code = ShortLink::codeOf($code);

        if (ItemNumbers::hasWrongCheckDigit($code)) {
            return "Die Prüfziffer von {$code} stimmt nicht. Ist eine Ziffer vertippt?";
        }

        return $place ? "Zu {$code} gibt es keinen Standort und keine Box." : "Zu {$code} gibt es nichts.";
    }
}
