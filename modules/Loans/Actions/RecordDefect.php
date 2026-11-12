<?php

namespace Modules\Loans\Actions;

use App\Actions\Inventory\ChangeItemCondition;
use App\Models\Item;
use App\Models\ItemCondition;
use App\Models\ItemPhoto;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Modules\Loans\Enums\ReturnState;
use Modules\Loans\Models\Loan;

/**
 * What a defect means for the item, at the return and at the issue: "beschädigt" changes the
 * condition (the dialog of phase 1, linked to the loan), "verschmutzt" and "unvollständig" write
 * an entry with the photos into the history without changing the condition. "Fehlt" changes
 * nothing here; the return books it.
 */
class RecordDefect
{
    public function __construct(private readonly ChangeItemCondition $changeCondition) {}

    /**
     * @param  string  $when  part of the history entry, e.g. "zurück aus" or "bei der Ausgabe von"
     * @param  list<UploadedFile>  $photos
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Loan $loan, Item $item, ReturnState $state, string $note, ?ItemCondition $condition, array $photos, string $when): void
    {
        $text = ucfirst("{$state->label()} {$when} {$loan->number}: {$note}");

        if ($state === ReturnState::Damaged) {
            if ($condition === null) {
                throw ValidationException::withMessages(['condition_id' => 'Wähle den neuen Zustand.']);
            }
            $this->changeCondition->handle($actor, $item, $condition, $text, $photos, $loan->id);
        } elseif (in_array($state, [ReturnState::Dirty, ReturnState::Incomplete], true)) {
            $change = $item->conditionChanges()->create([
                'from_condition' => $item->condition->name,
                'to_condition' => $item->condition->name,
                'reason' => $text,
                'user_id' => $actor->id,
                'loan_id' => $loan->id,
            ]);
            ItemPhoto::uploadAll($item, $photos, $change);
        }
    }
}
