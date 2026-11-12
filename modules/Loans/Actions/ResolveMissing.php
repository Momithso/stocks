<?php

namespace Modules\Loans\Actions;

use App\Actions\Inventory\MergeItem;
use App\Enums\ItemKind;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Enums\ReturnState;
use Modules\Loans\Models\LoanItem;

/**
 * Something that went missing at a return turns up again ("Wieder da"). Missing pieces of a bulk
 * item go straight back to the stock (MergeItem); a single item waits under Rückgaben to be put
 * back like after a return (StowItem). When nothing is missing any more and there are no other
 * defects, the defects of the loan close by themselves. Writing off as lost is part of
 * ReturnLoan::closeDefects().
 */
class ResolveMissing
{
    public function __construct(private readonly MergeItem $mergeItem) {}

    /**
     * @return string what happened, for the message
     *
     * @throws ValidationException
     */
    public function found(User $actor, LoanItem $row): string
    {
        return DB::transaction(function () use ($actor, $row) {
            // locked and checked again, so a second tap does not book it twice
            LoanItem::whereKey($row->id)->lockForUpdate()->first();
            if (! LoanItem::whereKey($row->id)->stillMissing()->exists()) {
                throw ValidationException::withMessages(['row' => 'Das wird nicht mehr vermisst.']);
            }
            $row->refresh()->load(['missingItem', 'issuedItem', 'loan']);
            $item = $row->missingItem;

            $row->forceFill(['missing_resolution' => 'found', 'missing_resolved_at' => now()])->save();

            $message = "{$item->number} ist wieder da. Unter Rückgaben einräumen.";
            if ($item->kind === ItemKind::Bulk && $item->mergeTarget() !== null) {
                $origin = $this->mergeItem->handle($actor, $item, note: "Wieder da nach {$row->loan->number}.");
                // went back to the lent part that still waits to be put back: it now brings these along
                if ($origin->id === $row->issued_item_id) {
                    $row->forceFill(['returned_quantity' => ($row->returned_quantity ?? 0) + $item->quantity])->save();
                }
                $message = "{$item->quantity} {$item->unit} wieder da, zurück zu {$origin->number}.";
            }

            $this->closeWhenSettled($row);

            return $message;
        });
    }

    /**
     * The defects of the loan close when nothing is missing any more and there was no other
     * defect (damaged, dirty, incomplete), the same states that close a loan with defects.
     */
    private function closeWhenSettled(LoanItem $row): void
    {
        $loan = $row->loan;
        $open = $loan->loanItems()->stillMissing()->exists()
            || $loan->loanItems()->whereNotNull('return_state')->whereNotIn('return_state', [ReturnState::Ok, ReturnState::Missing])->exists();
        if ($loan->status === LoanStatus::ClosedWithDefects && $loan->defects_closed_at === null && ! $open) {
            $loan->forceFill(['defects_closed_at' => now(), 'defects_note' => 'Alles Fehlende ist wieder da.'])->save();
        }
    }
}
