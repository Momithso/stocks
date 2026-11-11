<?php

namespace Modules\Loans\Actions;

use App\Enums\ItemKind;
use App\Models\Item;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Models\Loan;
use Modules\Loans\Models\LoanItem;
use Modules\Loans\Support\Availability;

/**
 * Add, change and remove positions while nothing is issued. A draft takes everything and shows the
 * collisions; a confirmed loan only takes what is free, so it never holds an item twice.
 */
class ChangePositions
{
    public function __construct(private readonly Availability $availability) {}

    /**
     * Add an item or a box. A bulk item that is already a position gets the quantity added.
     *
     * @throws ValidationException
     */
    public function add(Loan $loan, Item $item, int $quantity = 1): LoanItem
    {
        $this->ensureOpen($loan);
        if ($problem = $this->availability->problemOf($item)) {
            throw ValidationException::withMessages(['item' => $problem]);
        }
        $quantity = $item->kind === ItemKind::Bulk ? $quantity : 1;

        $positions = $loan->positions()->with('item')->get();
        foreach ($positions as $position) {
            if ($position->item->is_container && $item->isInside($position->item)) {
                throw ValidationException::withMessages(['item' => "{$item->number} liegt in {$position->item->number}, die Box ist schon in der Buchung."]);
            }
        }

        return DB::transaction(function () use ($loan, $item, $quantity, $positions) {
            $position = $positions->firstWhere('item_id', $item->id);
            if ($position && $item->kind !== ItemKind::Bulk) {
                throw ValidationException::withMessages(['item' => "{$item->number} ist schon in der Buchung."]);
            }

            $position ??= $loan->loanItems()->make(['item_id' => $item->id, 'quantity' => 0]);
            $position->quantity += $quantity;
            $this->ensureQuantity($item, $position->quantity);
            $position->save();
            $this->ensureFree($loan, $position);

            return $position;
        });
    }

    /**
     * Change the quantity of a bulk item.
     *
     * @throws ValidationException
     */
    public function setQuantity(Loan $loan, LoanItem $position, int $quantity): void
    {
        $this->ensureOpen($loan);
        $this->ensureQuantity($position->item, $quantity);

        DB::transaction(function () use ($loan, $position, $quantity) {
            $position->update(['quantity' => $quantity]);
            $this->ensureFree($loan, $position);
        });
    }

    /**
     * Take a position out of the loan.
     *
     * @throws ValidationException
     */
    public function remove(Loan $loan, LoanItem $position): void
    {
        $this->ensureOpen($loan);
        $position->delete();
    }

    /**
     * @throws ValidationException
     */
    private function ensureOpen(Loan $loan): void
    {
        if (! $loan->status->isOpen()) {
            throw ValidationException::withMessages(['item' => 'Nach der Ausgabe sind die Positionen fest. Was dazukommen soll, wird eine zweite Buchung in derselben Veranstaltung.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private function ensureQuantity(Item $item, int $quantity): void
    {
        if ($item->kind === ItemKind::Bulk && ($quantity < 1 || $quantity > $item->quantity)) {
            throw ValidationException::withMessages(['quantity' => "Die Menge muss zwischen 1 und {$item->quantity} liegen."]);
        }
    }

    /**
     * A confirmed loan only takes what is free in its time.
     *
     * @throws ValidationException
     */
    private function ensureFree(Loan $loan, LoanItem $position): void
    {
        if ($loan->status !== LoanStatus::Confirmed) {
            return;
        }

        $check = $this->availability->check($position->item, $position->quantity, $loan->pickup_at, $loan->return_at, $loan);
        if ($check['blocked']) {
            throw ValidationException::withMessages(['item' => $this->availability->message($position->item, $check)]);
        }
    }
}
