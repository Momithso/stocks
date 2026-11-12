<?php

namespace Modules\Loans\Actions;

use App\Actions\Inventory\MoveItem;
use App\Actions\Inventory\RetireItem;
use App\Enums\MovementType;
use App\Enums\RetireReason;
use App\Models\Item;
use App\Models\ItemCondition;
use App\Models\ItemMovement;
use App\Models\ItemUnit;
use App\Models\User;
use App\Support\Inventory\CodeLookup;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Loans\Enums\DocumentType;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Enums\ReturnState;
use Modules\Loans\Enums\SignatureRole;
use Modules\Loans\Models\Loan;
use Modules\Loans\Models\LoanItem;
use Modules\Loans\Support\LoanDocuments;
use Modules\Loans\Support\SignatureInput;
use Modules\Loans\Support\Snapshot;

/**
 * The return of an issued loan and the later inspection of what came back unchecked. Like the
 * issue, the state is kept on the server until it is booked:
 *
 * 1. check() ticks a row off (scan or by hand), a box with its contents (not for boxes that came
 *    back unchecked before); defect() marks a row as missing, damaged, dirty or incomplete,
 * 2. complete() books the handled rows with a receipt and the signatures; what is not back yet keeps
 *    the loan issued (partial return, every part gets its own receipt),
 * 3. acceptUnchecked() books everything at once without checking: the time stops, the loan waits
 *    for the inspection ("Prüfung offen"),
 * 4. completeInspection() closes the inspection; every row of it must be handled one by one.
 *
 * Mode "inspect" works on the rows that came back unchecked, mode "return" on the rows still out.
 */
class ReturnLoan
{
    public function __construct(
        private readonly CodeLookup $codes,
        private readonly MoveItem $moveItem,
        private readonly RetireItem $retireItem,
        private readonly RecordDefect $recordDefect,
        private readonly LoanDocuments $documents,
    ) {}

    /**
     * Rows the mode works on: still out (return) or back but unchecked (inspect). The rest of a
     * partial issue never left and is not part of the return.
     *
     * @return Collection<int, LoanItem>
     */
    public function rows(Loan $loan, bool $inspect): Collection
    {
        $query = $loan->loanItems()->with(['item.condition', 'issuedItem', 'parent']);

        return $inspect
            ? $query->whereNotNull('returned_at')->whereNull('inspected_at')->get()
            : $query->whereNotNull('issued_at')->whereNull('returned_at')->get();
    }

    /**
     * Tick a row off by a scanned code or by hand. A bulk item gets the counted quantity; less than
     * lent is "missing", except for consumables (used up, booked out at the end). Ticking a ticked
     * row again takes a changed count. $quantities are the counts of bulk rows in a ticked box.
     *
     * @param  array<int, int>  $quantities  counted quantity by row id
     *
     * @throws ValidationException
     */
    public function check(User $actor, Loan $loan, bool $inspect, ?string $code, ?int $rowId, ?int $quantity = null, array $quantities = []): LoanItem
    {
        $this->ensureMode($loan, $inspect);
        $rows = $this->rows($loan, $inspect);
        $row = $rowId ? $rows->firstWhere('id', $rowId) : $this->rowOf($rows, (string) $code);
        if ($row === null) {
            throw ValidationException::withMessages(['code' => $rowId ? 'Die Position ist hier nicht offen.' : "{$code} gehört nicht zu dem, was von {$loan->number} noch offen ist."]);
        }

        $fill = ['return_checked_at' => now(), 'return_checked_by' => $actor->id, 'return_checked_by_scan' => $rowId === null];
        // a wrong count in the box leaves nothing ticked
        DB::transaction(function () use ($row, $fill, $quantity, $inspect, $quantities) {
            $row->forceFill([...$fill, ...$this->counted($row, $quantity)])->save();
            $this->tickAlong($row, $fill, $inspect, $quantities);
        });

        return $row;
    }

    /**
     * Fields of a counted bulk row: the quantity and "missing" when less came back.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function counted(LoanItem $row, ?int $quantity): array
    {
        if (! $row->isBulk()) {
            return [];
        }
        $quantity ??= $row->quantity;
        if ($quantity < 0 || $quantity > $row->quantity) {
            throw ValidationException::withMessages(['quantity' => "Die Menge von {$row->item->number} muss zwischen 0 und {$row->quantity} liegen."]);
        }
        $short = $row->quantity - $quantity;
        if ($short > 0 && ! ItemUnit::isConsumable($row->item->unit)) {
            return ['returned_quantity' => $quantity, 'return_state' => ReturnState::Missing, 'return_note' => "{$short} {$row->item->unit} fehlen."];
        }

        return $row->return_state === ReturnState::Missing
            ? ['returned_quantity' => $quantity, 'return_state' => null, 'return_note' => null]
            : ['returned_quantity' => $quantity];
    }

    /**
     * Mark a row as missing, damaged or dirty, with note and photos. "Damaged" changes the
     * condition of the item (with the loan linked); "dirty" adds an entry with the photos to its
     * history without changing the condition.
     *
     * @param  list<UploadedFile>  $photos
     *
     * @throws ValidationException
     */
    public function defect(User $actor, Loan $loan, bool $inspect, LoanItem $row, ReturnState $state, string $note, ?ItemCondition $condition, array $photos, ?int $quantity = null): void
    {
        $this->ensureMode($loan, $inspect);
        $this->ensureInScope($loan, $inspect, $row);
        // damaged or dirty: the count of a bulk row counts, pieces short stay in the note
        $returned = $state === ReturnState::Missing ? 0 : ($row->isBulk() ? ($this->counted($row, $quantity ?? $row->returned_quantity)['returned_quantity']) : null);
        if ($row->isBulk() && $returned < $row->quantity && $state !== ReturnState::Missing && ! ItemUnit::isConsumable($row->item->unit)) {
            $note .= ' '.($row->quantity - $returned)." {$row->item->unit} fehlen.";
        }
        $item = $row->physicalItem();

        DB::transaction(function () use ($actor, $loan, $row, $state, $note, $condition, $photos, $item, $returned) {
            $this->recordDefect->handle($actor, $loan, $item, $state, $note, $condition, $photos, 'zurück aus');

            $row->forceFill([
                'return_state' => $state,
                'return_note' => $note,
                'return_checked_at' => $row->return_checked_at ?? now(),
                'return_checked_by' => $actor->id,
                'returned_quantity' => $returned,
            ])->save();
        });
    }

    /**
     * Take the tick and the marks of a row back. A changed condition stays changed.
     *
     * @throws ValidationException
     */
    public function reset(Loan $loan, bool $inspect, LoanItem $row): void
    {
        $this->ensureMode($loan, $inspect);
        $this->ensureInScope($loan, $inspect, $row);
        $reset = ['return_checked_at' => null, 'return_checked_by' => null, 'return_checked_by_scan' => false, 'returned_quantity' => null, 'return_state' => null, 'return_note' => null];
        $row->forceFill($reset)->save();

        // the contents of a box go back with it; a box with a row taken back is not complete
        $loan->loanItems()->whereIn('id', $row->descendantIds())->whereNull('returned_at')->update($reset);
        for ($parent = $row->parent; $parent !== null; $parent = $parent->parent) {
            if ($parent->return_state === null) {
                $parent->forceFill(['return_checked_at' => null, 'return_checked_by' => null, 'return_checked_by_scan' => false])->save();
            }
        }
    }

    /**
     * A ticked box takes its contents along (not in the inspection and not for a box that came back
     * unchecked before: those rows are ticked one by one), and a box whose rows are all handled is
     * ticked itself. Bulk rows in the box take their count from $quantities, else the full quantity.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<int, int>  $quantities  counted quantity by row id
     */
    private function tickAlong(LoanItem $row, array $fields, bool $inspect, array $quantities): void
    {
        $scope = fn () => $row->loan->loanItems()->where(fn ($query) => $inspect ? $query->whereNotNull('returned_at')->whereNull('inspected_at') : $query->whereNull('returned_at'));

        if (! $inspect && ! $row->isUncheckedBox()) {
            foreach ($scope()->whereIn('id', $row->descendantIds())->whereNull('return_checked_at')->whereNull('return_state')->with('item')->get() as $child) {
                $child->forceFill([...$fields, ...$this->counted($child, $quantities[$child->id] ?? null)])->save();
            }
        }

        for ($parent = $row->parent; $parent !== null && ! $parent->isReturnHandled(); $parent = $parent->parent) {
            $open = $scope()->where('parent_id', $parent->id)->whereNull('return_checked_at')->whereNull('return_state')->exists();
            if ($open) {
                break;
            }
            $parent->forceFill($fields)->save();
        }
    }

    /**
     * Book the handled rows as back, with receipt and signatures.
     *
     * @param  array<string, string|null>  $signatures  png data uris by role (returner, receiver)
     *
     * @throws ValidationException
     */
    public function complete(User $actor, Loan $loan, array $signatures, bool $onPaper, string $returner, ?string $device): void
    {
        $this->ensureMode($loan, false);
        $rows = $this->rows($loan, false)->filter(fn (LoanItem $row) => $row->isReturnHandled());
        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['loan' => 'Noch nichts abgehakt.']);
        }
        $this->book($actor, $loan, $rows, $signatures, $onPaper, $returner, $device);
    }

    /**
     * Accept everything that is still out without checking it ("ungeprüft, Mängel vorbehalten").
     * Rows already handled are booked as they are, the others wait for the inspection.
     *
     * @param  array<string, string|null>  $signatures
     *
     * @throws ValidationException
     */
    public function acceptUnchecked(User $actor, Loan $loan, array $signatures, bool $onPaper, string $returner, ?string $device): void
    {
        $this->ensureMode($loan, false);
        $this->book($actor, $loan, $this->rows($loan, false), $signatures, $onPaper, $returner, $device);
    }

    /**
     * Finish the inspection of what came back unchecked. Every row must be handled.
     *
     * @throws ValidationException
     */
    public function completeInspection(User $actor, Loan $loan): void
    {
        $this->ensureMode($loan, true);

        DB::transaction(function () use ($actor, $loan) {
            // locked and checked again, so a second tap does nothing
            $loan->lockForTask();
            $this->ensureMode($loan, true);
            $rows = $this->rows($loan, true);
            $open = $rows->reject(fn (LoanItem $row) => $row->isReturnHandled());
            if ($open->isNotEmpty()) {
                throw ValidationException::withMessages(['loan' => 'Noch nicht geprüft: '.$open->map(fn (LoanItem $row) => $row->physicalItem()->number)->implode(', ').'.']);
            }

            foreach ($rows as $row) {
                $row->forceFill(['inspected_at' => now(), 'inspected_by' => $actor->id])->save();
                $this->bookItem($actor, $loan, $row, false);
            }
            $this->finish($loan);
        });
    }

    /**
     * Close the defects of a loan with a note (e.g. "Ersatz bezahlt"). What is still missing then is
     * retired as lost.
     *
     * @throws ValidationException
     */
    public function closeDefects(User $actor, Loan $loan, string $note): void
    {
        DB::transaction(function () use ($actor, $loan, $note) {
            $loan->lockForTask();
            if ($loan->status !== LoanStatus::ClosedWithDefects || $loan->defects_closed_at !== null) {
                throw ValidationException::withMessages(['loan' => 'Die Buchung hat keine offenen Mängel.']);
            }

            // what is still missing is written off as lost
            foreach ($loan->loanItems()->stillMissing()->with('missingItem')->get() as $row) {
                $this->retireItem->handle($actor, $row->missingItem, RetireReason::Lost, "Verloren nach {$loan->number}. {$note}");
                $row->forceFill(['missing_resolution' => 'lost', 'missing_resolved_at' => now()])->save();
            }
            $loan->forceFill(['defects_closed_at' => now(), 'defects_note' => $note])->save();
        });
    }

    /**
     * Book rows as back: receipt with signatures, movement log, consumables, status.
     *
     * @param  Collection<int, LoanItem>  $rows
     * @param  array<string, string|null>  $signatures
     *
     * @throws ValidationException
     */
    private function book(User $actor, Loan $loan, Collection $rows, array $signatures, bool $onPaper, string $returner, ?string $device): void
    {
        if (! $loan->isInternal() && ! $onPaper) {
            foreach ([SignatureRole::Returner, SignatureRole::Receiver] as $role) {
                if (! SignatureInput::isPng($signatures[$role->value] ?? null)) {
                    throw ValidationException::withMessages(["signatures.{$role->value}" => 'Die Unterschrift fehlt.']);
                }
            }
        }

        DB::transaction(function () use ($actor, $loan, $rows, $signatures, $onPaper, $returner, $device) {
            // locked, and only rows that are still out: a second tap or a repeated request finds
            // them booked and stops
            $loan->lockForTask();
            $this->ensureMode($loan, false);
            $rows = $this->rows($loan, false)->whereIn('id', $rows->modelKeys())->values();
            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['loan' => 'Das ist schon als zurück gebucht.']);
            }

            $snapshot = $this->documents->returnSnapshot($loan, $rows, $returner, $actor);
            $document = $loan->documents()->create(['type' => DocumentType::ReturnReceipt, 'snapshot' => $snapshot, 'hash' => Snapshot::hash($snapshot), 'created_by' => $actor->id]);
            if (! $loan->isInternal()) {
                SignatureInput::store($loan, $document, [
                    SignatureRole::Returner->value => ['name' => $returner, 'image' => $signatures[SignatureRole::Returner->value] ?? null, 'user' => null],
                    SignatureRole::Receiver->value => ['name' => $actor->name, 'image' => $signatures[SignatureRole::Receiver->value] ?? null, 'user' => $actor],
                ], $onPaper, $device);
            }

            foreach ($rows as $row) {
                $checked = $row->isReturnHandled();
                // first the row, so the item is no longer locked by the loan
                $row->forceFill([
                    'returned_at' => now(),
                    'return_document_id' => $document->id,
                    'inspected_at' => $checked ? now() : null,
                    'inspected_by' => $checked ? $actor->id : null,
                ])->save();

                // a bulk row with pieces missing still brings the rest back
                if ($row->parent_id === null && ($row->return_state !== ReturnState::Missing || ($row->isBulk() && $row->returned_quantity > 0))) {
                    ItemMovement::record($row->physicalItem(), MovementType::Return, null, null, $actor, $row->returned_quantity ?? $row->quantity, "Zurückgenommen von {$loan->number}.");
                }
                if ($checked) {
                    $this->bookItem($actor, $loan, $row, true);
                }
            }

            $this->finishIfBack($loan);
        });
    }

    /**
     * Close the loan once every row is back. A rest of a partial issue that may still leave keeps
     * it open; IssueLoan calls this when such a rest is dropped.
     */
    public function finishIfBack(Loan $loan): void
    {
        if ($loan->status === LoanStatus::Issued && ! $loan->loanItems()->whereNull('returned_at')->exists()) {
            $loan->forceFill(['returned_at' => now()])->save();
            $this->finish($loan);
        }
    }

    /**
     * What a checked row means for its item: used up consumables are booked out, missing contents
     * of a box leave the box, and missing pieces of a bulk item are split off without a place, like
     * a missing single item.
     */
    private function bookItem(User $actor, Loan $loan, LoanItem $row, bool $atReturn): void
    {
        $item = $row->physicalItem()->refresh();
        $short = $row->isBulk() ? $row->quantity - ($row->returned_quantity ?? $row->quantity) : 0;

        $missing = null;
        if ($short > 0 && $short <= $item->quantity) {
            if (ItemUnit::isConsumable($row->item->unit)) {
                $this->retireItem->handle($actor, $item, RetireReason::Consumed, "Verbraucht mit {$loan->number}.", $short);
            } elseif ($short < $item->quantity || $item->place() !== null) {
                $missing = $this->moveItem->takeAway($actor, $item, MovementType::Move, $short, "Fehlt nach der Rückgabe von {$loan->number}.");
            } else {
                $missing = $item;
            }
        }

        // a missing item has no place any more; a missing row of a box leaves the box
        if ($row->return_state === ReturnState::Missing && ! $row->isBulk()) {
            if ($item->place() !== null) {
                $this->moveItem->takeAway($actor, $item, MovementType::Move, note: "Fehlt nach der Rückgabe von {$loan->number}.");
            }
            $missing = $item;
        }

        // "vermisst" until it turns up (ResolveMissing::found) or is written off with the defects
        if ($missing !== null) {
            $row->forceFill(['missing_item_id' => $missing->id])->save();
        }
    }

    /**
     * Status of a loan whose rows are all back.
     */
    private function finish(Loan $loan): void
    {
        $rows = $loan->loanItems()->get();
        $status = match (true) {
            $rows->contains(fn (LoanItem $row) => $row->inspected_at === null) => LoanStatus::ReturnedUnchecked,
            $rows->contains(fn (LoanItem $row) => $row->return_state !== null && $row->return_state !== ReturnState::Ok) => LoanStatus::ClosedWithDefects,
            default => LoanStatus::Closed,
        };

        $loan->forceFill([
            'status' => $status,
            'inspected_at' => $status === LoanStatus::ReturnedUnchecked ? null : now(),
        ])->save();
    }

    /**
     * Row with the number of a code (the part that left, or the item).
     *
     * @param  Collection<int, LoanItem>  $rows
     */
    private function rowOf(Collection $rows, string $code): ?LoanItem
    {
        $item = $this->codes->find($code);

        if (! $item instanceof Item) {
            return null;
        }

        // the part that left has a number of its own, but often carries the label of its origin
        return $rows->first(fn (LoanItem $row) => $row->physicalItem()->is($item))
            ?? $rows->first(fn (LoanItem $row) => $row->issued_item_id !== null && $row->item->is($item));
    }

    /**
     * @throws ValidationException
     */
    private function ensureMode(Loan $loan, bool $inspect): void
    {
        if ($loan->status !== ($inspect ? LoanStatus::ReturnedUnchecked : LoanStatus::Issued)) {
            throw ValidationException::withMessages(['loan' => $inspect ? 'Bei dieser Buchung ist keine Prüfung offen.' : 'Zurücknehmen lässt sich nur eine ausgegebene Buchung.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private function ensureInScope(Loan $loan, bool $inspect, LoanItem $row): void
    {
        if (! $this->rows($loan, $inspect)->contains('id', $row->id)) {
            throw ValidationException::withMessages(['loan' => 'Die Position ist hier nicht offen.']);
        }
    }
}
