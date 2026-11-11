<?php

namespace Modules\Loans\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Models\Loan;
use Modules\Loans\Support\Availability;

/**
 * Give an issued loan a later return time, if no following loan needs its items earlier.
 */
class ExtendLoan
{
    public function __construct(private readonly Availability $availability) {}

    /**
     * @throws ValidationException
     */
    public function handle(Loan $loan, Carbon $returnAt): void
    {
        if ($loan->status !== LoanStatus::Issued) {
            throw ValidationException::withMessages(['return_at' => 'Nur eine ausgegebene Buchung lässt sich verlängern.']);
        }
        if ($returnAt->lte($loan->return_at) || $returnAt->isPast()) {
            throw ValidationException::withMessages(['return_at' => 'Der neue Rückgabetermin muss nach dem alten und in der Zukunft liegen.']);
        }

        if ($problems = $this->availability->problems($loan, now(), $returnAt)) {
            throw ValidationException::withMessages(['return_at' => 'Bis dahin ist nicht alles frei: '.implode(' ', $problems)]);
        }

        $loan->update(['return_at' => $returnAt]);
    }
}
