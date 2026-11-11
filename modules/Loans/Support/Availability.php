<?php

namespace Modules\Loans\Support;

use App\Enums\ItemKind;
use App\Models\Item;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Models\Loan;
use Modules\Loans\Models\LoanItem;

/**
 * Whether an item is free for a time.
 *
 * An item is taken in a time if a confirmed or issued loan holds it in an overlapping time:
 * - directly, or through a box it lies in (a booked box takes its whole contents),
 * - a bulk item counts its quantity: free is the stock minus the quantities of overlapping loans,
 * - an overdue loan holds its items until they are back, so it overlaps every later time,
 * - between two loans the setting "loans.buffer_hours" stays free.
 *
 * Drafts reserve nothing; they are reported as "Vormerkung". A box whose single item is booked
 * elsewhere can be booked with a hint (it is incomplete then).
 */
class Availability
{
    /**
     * Tries to find the next free time.
     */
    private const NEXT_FREE_TRIES = 10;

    public function __construct(private readonly Settings $settings) {}

    /**
     * Check every position of a loan for its time.
     *
     * @return array<int, array{problem: string|null, conflicts: list<array<string, mixed>>, drafts: list<array<string, mixed>>, hints: list<string>, free: int|null, next_free: string|null, blocked: bool}> by loan item id
     */
    public function forLoan(Loan $loan): array
    {
        $result = [];
        foreach ($loan->positions()->with('item.condition')->get() as $position) {
            $result[$position->id] = $this->check($position->item, $position->quantity, $loan->pickup_at, $loan->return_at, $loan);
        }

        return $result;
    }

    /**
     * Check if all positions of a loan are free; the messages of those that are not.
     *
     * @return list<string>
     */
    public function problems(Loan $loan, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $messages = [];
        foreach ($loan->positions()->with('item.condition')->whereNull('returned_at')->get() as $position) {
            $check = $this->check($position->item, $position->quantity, $from ?? $loan->pickup_at, $to ?? $loan->return_at, $loan);
            if ($check['blocked']) {
                $messages[] = $this->message($position->item, $check);
            }
        }

        return $messages;
    }

    /**
     * Check an item for a time.
     *
     * "blocked" is true if the item can not be lent: not lendable at all ("problem"), held by another
     * loan ("conflicts"), or not enough of a bulk item left ("free"). "next_free" is the next start
     * time at which the same duration is free (null if unknown, e.g. while an overdue loan holds it).
     *
     * @param  Loan|null  $except  loan whose own positions do not count
     * @return array{problem: string|null, conflicts: list<array<string, mixed>>, drafts: list<array<string, mixed>>, hints: list<string>, free: int|null, next_free: string|null, blocked: bool}
     */
    public function check(Item $item, int $quantity, Carbon $from, Carbon $to, ?Loan $except = null): array
    {
        $problem = $this->problemOf($item);
        [$conflicts, $free] = $this->conflicts($item, $from, $to, $except);
        $blocked = $problem !== null || ! $this->isFree($item, $quantity, $conflicts, $free);

        return [
            'problem' => $problem,
            'conflicts' => collect($conflicts)->pluck('loan')->unique('id')->values()->all(),
            'drafts' => $this->drafts($item, $from, $to, $except),
            'hints' => $this->hints($item, $from, $to, $except),
            'free' => $item->kind === ItemKind::Bulk ? max(0, $free) : null,
            'next_free' => $blocked && $problem === null ? $this->nextFree($item, $quantity, $from, $to, $except)?->toIso8601String() : null,
            'blocked' => $blocked,
        ];
    }

    /**
     * A sentence for a blocked item, e.g. for the error when confirming.
     *
     * @param  array{problem: string|null, conflicts: list<array<string, mixed>>, free: int|null}  $check
     */
    public function message(Item $item, array $check): string
    {
        if ($check['problem'] !== null) {
            return $check['problem'];
        }
        $others = implode(', ', array_column($check['conflicts'], 'number'));

        return $check['free'] !== null
            ? "Von {$item->number} {$item->name} sind in dieser Zeit nur {$check['free']} {$item->unit} frei ({$others})."
            : "{$item->number} {$item->name} ist in dieser Zeit schon gebucht ({$others}).";
    }

    /**
     * Why an item can not be lent at all: retired or in a condition that counts as damage.
     */
    public function problemOf(Item $item): ?string
    {
        $missing = LoanItem::where('missing_item_id', $item->id)->stillMissing()->with('loan')->first();

        return match (true) {
            $item->isRetired() => "{$item->number} ist ausgemustert und lässt sich nicht verleihen.",
            $item->condition->is_defect => "{$item->number} ist {$item->condition->name} und lässt sich nicht verleihen.",
            $missing !== null => "{$item->number} wird seit {$missing->loan->number} vermisst und lässt sich nicht verleihen.",
            default => null,
        };
    }

    /**
     * Rows of other reserving loans that hold the item in the time, and how much of a bulk item is
     * left. A row holds the item if it is the item, or a box the item lies in.
     *
     * @return array{0: list<array{row: LoanItem, loan: array<string, mixed>}>, 1: int}
     */
    private function conflicts(Item $item, Carbon $from, Carbon $to, ?Loan $except): array
    {
        $boxIds = $this->boxesAround($item);
        $rows = $this->overlapping(LoanStatus::reserving(), $from, $to, $except)
            ->where(fn (Builder $query) => $query
                ->where('loan_items.item_id', $item->id)
                ->orWhere('loan_items.issued_item_id', $item->id)
                ->orWhereIn('loan_items.item_id', $boxIds))
            ->get();

        $conflicts = $rows->map(fn (LoanItem $row) => ['row' => $row, 'loan' => $this->loanSummary($row->loan)])->unique(fn (array $conflict) => $conflict['row']->id)->values()->all();

        if ($item->kind !== ItemKind::Bulk) {
            return [$conflicts, 0];
        }

        // stock: what is here plus the parts that are out with an issued loan and come back
        $out = LoanItem::where('item_id', $item->id)->whereNotNull('issued_item_id')->whereNull('returned_at')
            ->whereHas('loan', fn (Builder $query) => $query->where('status', LoanStatus::Issued))->sum('quantity');
        $booked = $rows->where('item_id', $item->id)->sum('quantity');

        return [$conflicts, $item->quantity + (int) $out - (int) $booked];
    }

    /**
     * Check if the item is free despite the conflicts: a bulk item if enough is left, unless a row
     * holds it as a whole (a box around it, or it is a part that is out with a loan).
     *
     * @param  list<array{row: LoanItem}>  $conflicts
     */
    private function isFree(Item $item, int $quantity, array $conflicts, int $free): bool
    {
        if ($item->kind !== ItemKind::Bulk) {
            return $conflicts === [];
        }

        foreach ($conflicts as $conflict) {
            if ($conflict['row']->item_id !== $item->id) {
                return false;
            }
        }

        return $free >= $quantity;
    }

    /**
     * Drafts that note the item (or a box around it) for an overlapping time.
     *
     * @return list<array<string, mixed>>
     */
    private function drafts(Item $item, Carbon $from, Carbon $to, ?Loan $except): array
    {
        return $this->overlapping([LoanStatus::Draft], $from, $to, $except)
            ->where(fn (Builder $query) => $query->where('loan_items.item_id', $item->id)->orWhereIn('loan_items.item_id', $this->boxesAround($item)))
            ->get()
            ->map(fn (LoanItem $row) => [...$this->loanSummary($row->loan), 'created_by' => $row->loan->creator?->name])
            ->unique('id')->values()->all();
    }

    /**
     * Hints that do not block: items of the box booked elsewhere (the box is incomplete then), and
     * a box that came back unchecked.
     *
     * @return list<string>
     */
    private function hints(Item $item, Carbon $from, Carbon $to, ?Loan $except): array
    {
        $hints = [];
        if ($item->is_container) {
            $contents = $this->contentsOf($item);
            $rows = $contents === [] ? collect() : $this->overlapping(LoanStatus::reserving(), $from, $to, $except)->whereIn('loan_items.item_id', $contents)->with('item')->get();
            foreach ($rows as $row) {
                $hints[] = "Unvollständig: {$row->item->number} {$row->item->name} aus der Box ist in {$row->loan->number} gebucht.";
            }
        }

        $unchecked = LoanItem::where('item_id', $item->id)->whereNotNull('returned_at')->whereNull('inspected_at')->with('loan')->first();
        if ($unchecked) {
            $hints[] = "Ungeprüft zurück aus {$unchecked->loan->number}; der Inhalt ist noch nicht kontrolliert.";
        }

        return $hints;
    }

    /**
     * Next start time at which the item is free for the same duration, null if none is found.
     */
    private function nextFree(Item $item, int $quantity, Carbon $from, Carbon $to, ?Loan $except): ?Carbon
    {
        $duration = $from->diffInSeconds($to);
        $start = $from->copy();

        for ($try = 0; $try < self::NEXT_FREE_TRIES; $try++) {
            [$conflicts, $free] = $this->conflicts($item, $start, $start->copy()->addSeconds($duration), $except);
            if ($this->isFree($item, $quantity, $conflicts, $free)) {
                return $start;
            }

            $ends = array_map(fn (array $conflict) => $conflict['row']->loan->isOverdue() ? null : $conflict['row']->loan->return_at, $conflicts);
            // an overdue loan holds the item until it is back: no date can be named
            if (in_array(null, $ends, true)) {
                return null;
            }
            $start = Carbon::createFromTimestamp(max(array_map(fn (Carbon $end) => $end->getTimestamp(), $ends)), config('app.timezone'))->addHours($this->buffer());
        }

        return null;
    }

    /**
     * Rows of loans in the given statuses that overlap the time, without the rows that are back.
     *
     * @param  list<LoanStatus>  $statuses
     * @return Builder<LoanItem>
     */
    private function overlapping(array $statuses, Carbon $from, Carbon $to, ?Loan $except): Builder
    {
        $buffer = $this->buffer();

        return LoanItem::query()
            ->select('loan_items.*')
            ->join('loans', 'loans.id', '=', 'loan_items.loan_id')
            ->whereIn('loans.status', $statuses)
            ->whereNull('loan_items.returned_at')
            ->when($except, fn (Builder $query) => $query->where('loans.id', '!=', $except->id))
            ->where('loans.pickup_at', '<', $to->copy()->addHours($buffer))
            ->where(fn (Builder $query) => $query
                ->where('loans.return_at', '>', $from->copy()->subHours($buffer))
                // overdue loans hold their items until they are back
                ->orWhere(fn (Builder $query) => $query->where('loans.status', LoanStatus::Issued)->where('loans.return_at', '<', now())))
            ->with('loan.creator', 'loan.responsible', 'loan.responsibleUser', 'loan.borrower');
    }

    /**
     * Ids of the boxes the item lies in, the innermost first.
     *
     * @return list<int>
     */
    public function boxesAround(Item $item): array
    {
        return $this->boxesAroundAll(collect([$item]))[$item->id];
    }

    /**
     * Ids of the boxes around each item, the innermost first, with one query per box level.
     *
     * @param  Collection<int, Item>  $items
     * @return array<int, list<int>>
     */
    public function boxesAroundAll(Collection $items): array
    {
        $containerOf = $items->pluck('container_id', 'id')->all();
        // the limit only protects against broken data
        for ($depth = 0; $depth < 20; $depth++) {
            $unknown = array_diff(array_filter($containerOf), array_keys($containerOf));
            if ($unknown === []) {
                break;
            }
            $containerOf += Item::whereIn('id', $unknown)->pluck('container_id', 'id')->all();
        }

        $boxes = [];
        foreach ($items as $item) {
            $boxes[$item->id] = [];
            for ($id = $containerOf[$item->id] ?? null, $depth = 0; $id !== null && $depth < 20; $depth++) {
                $boxes[$item->id][] = $id;
                $id = $containerOf[$id] ?? null;
            }
        }

        return $boxes;
    }

    /**
     * Ids of everything in a box, boxes in it included.
     *
     * @return list<int>
     */
    public function contentsOf(Item $box): array
    {
        $ids = [];
        $level = [$box->id];
        for ($depth = 0; $level !== [] && $depth < 20; $depth++) {
            $level = Item::whereIn('container_id', $level)->active()->pluck('id')->all();
            $ids = [...$ids, ...$level];
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function loanSummary(Loan $loan): array
    {
        return $loan->only(['id', 'number']) + [
            'title' => $loan->title(),
            'pickup_at' => $loan->pickup_at->toIso8601String(),
            'return_at' => $loan->return_at->toIso8601String(),
            'overdue' => $loan->isOverdue(),
        ];
    }

    /**
     * Hours that stay free between two loans.
     */
    private function buffer(): int
    {
        return (int) $this->settings->get('loans.buffer_hours', 0);
    }
}
