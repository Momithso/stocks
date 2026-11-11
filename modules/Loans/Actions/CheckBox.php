<?php

namespace Modules\Loans\Actions;

use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Models\Loan;
use Modules\Loans\Models\LoanBoxCheck;
use Modules\Loans\Models\LoanItem;

/**
 * Check of a box with the pack pin on the public page of the box, without an account. The pin of a
 * loan opens only the boxes of this loan and only while it is confirmed or issued. Five wrong pins
 * lock the pin of every loan the box is in, until a member sets a new one.
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
     * Find the position of the box in the loan the pin belongs to.
     *
     * @throws ValidationException
     */
    public function unlock(Item $box, string $pin): LoanItem
    {
        $positions = self::positionsOf($box)->with('loan')->get();
        $open = $positions->reject(fn (LoanItem $position) => $position->loan->isPinLocked());

        $match = $open->first(fn (LoanItem $position) => $position->loan->pack_pin !== null && hash_equals($position->loan->pack_pin, $pin));
        if ($match) {
            $match->loan->forceFill(['pin_attempts' => 0])->save();

            return $match;
        }

        // counted without a transaction: the exception below must not undo the count
        foreach ($open as $position) {
            $position->loan->increment('pin_attempts');
        }
        $locked = $open->every(fn (LoanItem $position) => $position->loan->isPinLocked());

        throw ValidationException::withMessages(['pin' => $locked
            ? 'Die Pack-PIN ist nach zu vielen Fehlversuchen gesperrt. Jemand vom FSR muss sie neu setzen.'
            : 'Die Pack-PIN stimmt nicht.']);
    }

    /**
     * Store a check of the box: who, when, and which numbers were ticked off.
     *
     * @param  list<string>  $ticks
     *
     * @throws ValidationException
     */
    public function store(Item $box, string $pin, string $name, array $ticks, int $total): LoanBoxCheck
    {
        $position = $this->unlock($box, $pin);

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
