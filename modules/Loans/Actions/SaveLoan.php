<?php

namespace Modules\Loans\Actions;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Loans\Enums\LoanKind;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Models\Loan;
use Modules\Loans\Support\Availability;
use Modules\Loans\Support\LoanNumbers;

/**
 * Create a loan as draft or change its data. Times can only change while nothing is issued; a
 * confirmed loan keeps its items only if they are free in the new time.
 */
class SaveLoan
{
    public function __construct(private readonly LoanNumbers $numbers, private readonly Availability $availability) {}

    /**
     * @param  array{kind: string, borrower_contact_id?: int|null, responsible_contact_id?: int|null, responsible_user_id?: int|null, event_id?: int|null, pickup_at: string, return_at: string, purpose?: string|null, notes?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function handle(User $actor, ?Loan $loan, array $data): Loan
    {
        $pickup = Carbon::parse($data['pickup_at']);
        $return = Carbon::parse($data['return_at']);
        if ($return->lte($pickup)) {
            throw ValidationException::withMessages(['return_at' => 'Die Rückgabe muss nach der Abholung liegen.']);
        }

        if ($loan !== null && ! $loan->status->isOpen() && ($pickup->ne($loan->pickup_at) || $return->ne($loan->return_at))) {
            throw ValidationException::withMessages(['pickup_at' => 'Nach der Ausgabe lässt sich der Zeitraum nur noch verlängern.']);
        }

        // internal loans have a member instead of contacts, external ones no member
        $kind = LoanKind::from($data['kind']);
        $data = $kind === LoanKind::Internal
            ? [...$data, 'borrower_contact_id' => null, 'responsible_contact_id' => null]
            : [...$data, 'responsible_user_id' => null];

        return DB::transaction(function () use ($actor, $loan, $data, $pickup, $return) {
            $isNew = $loan === null;
            $loan ??= new Loan;
            $loan->fill($data);
            $loan->pickup_at = $pickup;
            $loan->return_at = $return;

            if ($isNew) {
                $loan->number = $this->numbers->next();
                $loan->status = LoanStatus::Draft;
                $loan->created_by = $actor->id;
            }
            $loan->save();

            if ($loan->status === LoanStatus::Confirmed && ($problems = $this->availability->problems($loan))) {
                throw ValidationException::withMessages(['pickup_at' => 'Im neuen Zeitraum ist nicht alles frei: '.implode(' ', $problems)]);
            }

            return $loan;
        });
    }
}
