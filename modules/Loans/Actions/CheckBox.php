<?php

namespace Modules\Loans\Actions;

use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Models\Loan;
use Modules\Loans\Models\LoanBoxCheck;
use Modules\Loans\Models\LoanItem;

/**
 * Check of a box with the pack pin on the public page of the box, without an account. The pin of a
 * loan opens only the boxes of this loan and only while it is confirmed or issued. Five wrong pins
 * from one address make the pin field of the box wait 15 minutes.
 */
class CheckBox
{
    /**
     * Positions of the box in loans whose pin works.
     *
     * @return Builder<LoanItem>
     */
    public static function positionsOf(Item $box): Builder
    {
        return LoanItem::where('item_id', $box->id)->whereNull('parent_id')->whereNull('returned_at')
            ->whereHas('loan', fn (Builder $query) => $query->whereIn('status', LoanStatus::reserving()));
    }

    /**
     * Wrong pins at a box from one address until the pin field of that box waits.
     */
    public const MAX_ATTEMPTS = 5;

    /**
     * Seconds the pin field of a box waits after too many wrong pins.
     */
    public const LOCK_SECONDS = 15 * 60;

    /**
     * Find the position of the box in the loan the pin belongs to. Wrong pins are limited per box
     * and address for a while; the pins themselves are never locked, so nobody without an account
     * can lock out the people packing.
     *
     * @throws ValidationException
     */
    public function unlock(Item $box, string $pin, string $ip): LoanItem
    {
        $key = "pack-pin:{$box->id}:{$ip}";
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages(['pin' => 'Zu viele falsche PINs. Versuche es in '.ceil(RateLimiter::availableIn($key) / 60).' Minuten noch einmal.']);
        }

        if ($match = self::match($box, $pin)) {
            RateLimiter::clear($key);

            return $match;
        }

        RateLimiter::hit($key, self::LOCK_SECONDS);

        throw ValidationException::withMessages(['pin' => 'Die Pack-PIN stimmt nicht.']);
    }

    /**
     * Position of the box in the loan of the pin, without counting a wrong pin (e.g. the pin kept
     * in the session when the page is shown).
     */
    public static function match(Item $box, string $pin): ?LoanItem
    {
        return self::positionsOf($box)->with('loan')->get()
            ->first(fn (LoanItem $position) => $position->loan->pack_pin !== null && hash_equals($position->loan->pack_pin, $pin));
    }

    /**
     * Store a check of the box: who, when, and which numbers were ticked off.
     *
     * @param  list<string>  $ticks
     *
     * @throws ValidationException
     */
    public function store(Item $box, string $pin, string $ip, string $name, array $ticks, int $total): LoanBoxCheck
    {
        $position = $this->unlock($box, $pin, $ip);

        return $position->boxChecks()->create([
            'name' => $name,
            'ticks' => array_values(array_unique($ticks)),
            'total' => $total,
            'checked_at' => now(),
        ]);
    }

    /**
     * Names used for checks of a loan, to choose from.
     *
     * @return list<string>
     */
    public static function namesOf(Loan $loan): array
    {
        return LoanBoxCheck::whereIn('loan_item_id', $loan->loanItems()->select('id'))->distinct()->orderBy('name')->pluck('name')->all();
    }
}
