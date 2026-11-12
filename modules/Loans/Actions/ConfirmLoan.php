<?php

namespace Modules\Loans\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Models\Loan;
use Modules\Loans\Notifications\LoanConfirmed;
use Modules\Loans\Support\Availability;
use Modules\Loans\Support\LoanNotifier;

/**
 * Confirm a draft: from now on it holds its items. Needs a responsible person with complete
 * contact data (internal loans: a member), at least one position and every position free. The loan
 * gets a random pack pin, and the borrower a mail with the link of the borrower page and the pin
 * (unless left out in the dialog).
 */
class ConfirmLoan
{
    public function __construct(private readonly Availability $availability, private readonly LoanNotifier $notifier) {}

    /**
     * @throws ValidationException
     */
    public function handle(Loan $loan, bool $notify = true): void
    {
        // one at a time, so two drafts can not be confirmed for the same item at once
        Availability::exclusively(fn () => DB::transaction(function () use ($loan, $notify) {
            $loan->lockForTask();
            if ($loan->status !== LoanStatus::Draft) {
                throw ValidationException::withMessages(['loan' => 'Nur ein Entwurf lässt sich bestätigen.']);
            }

            $messages = $this->responsibleProblems($loan);
            if (! $loan->positions()->exists()) {
                $messages[] = 'Die Buchung hat noch keine Positionen.';
            }
            $messages = [...$messages, ...$this->availability->problems($loan)];
            if ($messages) {
                throw ValidationException::withMessages(['loan' => implode(' ', $messages)]);
            }

            $loan->forceFill([
                'status' => LoanStatus::Confirmed,
                'confirmed_at' => now(),
                'pack_pin' => self::randomPin(),
            ])->save();

            if ($notify) {
                $this->notifier->toContact($loan, new LoanConfirmed($loan));
            }
        }));
    }

    /**
     * Why the responsible person is not enough for a confirmed loan.
     *
     * @return list<string>
     */
    public function responsibleProblems(Loan $loan): array
    {
        if ($loan->isInternal()) {
            return $loan->responsible_user_id ? [] : ['Für Eigenbedarf fehlt das verantwortliche Mitglied.'];
        }

        $responsible = $loan->responsible;
        if ($responsible === null) {
            return ['Die Buchung braucht eine verantwortliche Person.'];
        }

        $missing = $responsible->missingFields();

        return $missing ? ["Bei {$responsible->name} fehlen noch: ".implode(', ', $missing).'.'] : [];
    }

    /**
     * Six random digits.
     */
    public static function randomPin(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
