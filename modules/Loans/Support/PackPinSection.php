<?php

namespace Modules\Loans\Support;

use App\Models\Item;
use App\Support\Extensions\PublicBoxSection;
use Modules\Loans\Actions\CheckBox;
use Modules\Loans\Http\PackPinController;
use Modules\Loans\Models\LoanBoxCheck;

/**
 * Field "Pack-PIN" on the public page of a box, only while the box is in a confirmed or issued
 * loan. Nothing about the loan is shown before the right pin; after it the number and time of the
 * loan, the names used so far and the checks of this box.
 */
class PackPinSection implements PublicBoxSection
{
    /**
     * {@inheritDoc}
     */
    public function props(Item $box): ?array
    {
        if (! CheckBox::positionsOf($box)->exists()) {
            return null;
        }

        $pin = session(PackPinController::sessionKey($box));
        // a pin kept from before that no longer fits (new pin, loan over) is dropped
        $position = $pin !== null ? CheckBox::match($box, $pin) : null;
        if ($pin !== null && $position === null) {
            session()->forget(PackPinController::sessionKey($box));
        }

        return [
            'number' => $box->number,
            'unlocked' => $position ? [
                'loan' => $position->loan->number,
                'pickup_at' => $position->loan->pickup_at->toIso8601String(),
                'return_at' => $position->loan->return_at->toIso8601String(),
                'names' => CheckBox::namesOf($position->loan),
                'checks' => $position->boxChecks->map(fn (LoanBoxCheck $check) => ['name' => $check->name, 'checked_at' => $check->checked_at->toIso8601String(), 'ticked' => count($check->ticks), 'total' => $check->total]),
            ] : null,
        ];
    }
}
