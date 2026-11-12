<?php

namespace Modules\Loans\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Models\Loan;
use Modules\Loans\Models\LoanExtensionRequest;
use Modules\Loans\Notifications\LoanCancelled;
use Modules\Loans\Support\LoanNotifier;

/**
 * Cancel a loan before the issue. A reason is required; the change is in the audit. A confirmed
 * loan tells its borrower.
 */
class CancelLoan
{
    public function __construct(private readonly LoanNotifier $notifier) {}

    /**
     * @throws ValidationException
     */
    public function handle(Loan $loan, string $reason): void
    {
        if (! $loan->status->isOpen()) {
            throw ValidationException::withMessages(['loan' => 'Nur vor der Ausgabe lässt sich eine Buchung stornieren.']);
        }

        $loan->forceFill(['status' => LoanStatus::Cancelled, 'cancelled_at' => now(), 'cancelled_reason' => $reason])->save();
        LoanExtensionRequest::closeOpen($loan, 'Die Buchung ist storniert.');
        $this->notifier->toContact($loan, new LoanCancelled($loan));
    }
}
