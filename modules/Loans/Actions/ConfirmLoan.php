<?php

namespace Modules\Loans\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Models\Loan;
use Modules\Loans\Support\Availability;

/**
 * Confirm a draft: from now on it holds its items. Needs a responsible person with complete
 * contact data (internal loans: a member), at least one position and every position free. The loan
 * gets a random pack pin.
 */
class ConfirmLoan
{
    public function __construct(private readonly Availability $availability) {}

    /**
     * @throws ValidationException
     */
    public function handle(Loan $loan): void
    {
        if ($loan->status !== LoanStatus::Draft) {
            throw ValidationException::withMessages(['loan' => 'Nur ein Entwurf lässt sich bestätigen.']);
        }

        $messages = $this->responsibleProblems($loan);
        if (! $loan->positions()->exists()) {
            $messages[] = 'Die Buchung hat noch keine Positionen.';
        }

        DB::transaction(function () use ($loan, $messages) {
            // locks the loans of the items, so two drafts can not be confirmed for the same item at once
            Loan::reserving()->lockForUpdate()->pluck('id');
            $messages = [...$messages, ...$this->availability->problems($loan)];
            if ($messages) {
                throw ValidationException::withMessages(['loan' => implode(' ', $messages)]);
            }

            $loan->forceFill([
                'status' => LoanStatus::Confirmed,
                'confirmed_at' => now(),
                'pack_pin' => self::randomPin(),
                'pin_attempts' => 0,
            ])->save();
        });
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
