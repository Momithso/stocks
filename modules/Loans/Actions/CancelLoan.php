<?php

namespace Modules\Loans\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Models\Loan;

/**
 * Cancel a loan before the issue. A reason is required; the change is in the audit.
 */
class CancelLoan
{
    /**
     * @throws ValidationException
     */
    public function handle(Loan $loan, string $reason): void
    {
        if (! $loan->status->isOpen()) {
            throw ValidationException::withMessages(['loan' => 'Nur vor der Ausgabe lässt sich eine Buchung stornieren.']);
        }

        $loan->forceFill(['status' => LoanStatus::Cancelled, 'cancelled_at' => now(), 'cancelled_reason' => $reason])->save();
    }
}
