<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A kiosk pin: exactly config('kiosk.pin_length') digits and not easy to guess.
 */
class KioskPin implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $length = config('kiosk.pin_length');

        if (! is_string($value) || ! preg_match('/^\d{'.$length.'}$/', $value)) {
            $fail("Die PIN muss aus genau {$length} Ziffern bestehen.");

            return;
        }

        if ($this->isTrivial($value)) {
            $fail('Diese PIN ist zu leicht zu erraten.');
        }
    }

    /**
     * Repeated digits, ascending or descending sequences and listed pins are too easy to guess.
     */
    private function isTrivial(string $pin): bool
    {
        $digits = array_map('intval', str_split($pin));
        $steps = array_unique(array_map(fn (int $i) => $digits[$i + 1] - $digits[$i], range(0, count($digits) - 2)));

        return (count($steps) === 1 && in_array($steps[0], [-1, 0, 1], true))
            || in_array($pin, config('kiosk.trivial_pins'), true);
    }
}
