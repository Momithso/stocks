<?php

namespace App\Support\Inventory;

use App\Models\Item;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Item numbers: prefix of the instance, a running number with at least five digits and a check
 * digit, e.g. "FSR5-004218" (running number 00421, check digit 8).
 *
 * The check digit follows the Luhn algorithm, so a wrong digit or two swapped digits are noticed.
 * A number never changes, also not when the prefix is changed later.
 */
class ItemNumbers
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * Prefix for new numbers: the setting, or the short name of the organization without
     * spaces and special characters.
     */
    public function prefix(): string
    {
        $prefix = $this->settings->get('inventory.number_prefix')
            ?: preg_replace('/[^A-Z0-9]/', '', mb_strtoupper((string) $this->settings->get('organization.short_name')));

        return substr($prefix, 0, 6) ?: 'INV';
    }

    /**
     * Take the next number. Must run inside a transaction, the counter row stays locked until
     * the transaction ends, so two items created at the same time never get the same number.
     */
    public function next(): string
    {
        $prefix = $this->prefix();

        $value = DB::table('number_sequences')->where('prefix', $prefix)->lockForUpdate()->value('next_value');
        if ($value === null) {
            $value = 1;
            DB::table('number_sequences')->insert(['prefix' => $prefix, 'next_value' => 2]);
        } else {
            DB::table('number_sequences')->where('prefix', $prefix)->update(['next_value' => $value + 1]);
        }

        $digits = str_pad((string) $value, 5, '0', STR_PAD_LEFT);

        return $prefix.'-'.$digits.self::checkDigit($digits);
    }

    /**
     * Find an item by its number. Case and hyphens do not matter ("fsr5004218" works).
     */
    public function find(string $input): ?Item
    {
        $normalized = self::normalize($input);
        if ($normalized === '') {
            return null;
        }

        return Item::whereRaw("REPLACE(number, '-', '') = ?", [$normalized])->first();
    }

    /**
     * Check if the input has the form of an item number with a wrong check digit.
     */
    public static function hasWrongCheckDigit(string $input): bool
    {
        if (! preg_match('/^[A-Z0-9]{1,6}-(\d{5,})(\d)$/', mb_strtoupper(trim($input)), $matches)) {
            return false;
        }

        return self::checkDigit($matches[1]) !== (int) $matches[2];
    }

    /**
     * Check digit of a running number by the Luhn algorithm.
     */
    public static function checkDigit(string $digits): int
    {
        $sum = 0;
        // from the right, every second digit is doubled, starting with the rightmost one
        foreach (array_reverse(str_split($digits)) as $position => $digit) {
            $value = (int) $digit * ($position % 2 === 0 ? 2 : 1);
            $sum += $value > 9 ? $value - 9 : $value;
        }

        return (10 - $sum % 10) % 10;
    }

    /**
     * Upper case without spaces and hyphens.
     */
    public static function normalize(string $input): string
    {
        return preg_replace('/[\s-]/', '', mb_strtoupper($input));
    }
}
