<?php

namespace Modules\Loans\Http;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Models\Loan;
use Modules\Loans\Models\LoanItem;
use Modules\Loans\Support\Availability;

/**
 * Calendar of the loans: month view, timeline per item and the tab "Verleih" of an item. The
 * pages load their data as json for the shown time.
 */
class CalendarController extends Controller
{
    /**
     * Longest time the calendar loads at once, in days.
     */
    private const MAX_DAYS = 120;

    /**
     * Display the calendar.
     */
    public function show(): Response
    {
        return Inertia::render('Loans::Calendar', [
            'categories' => Category::options(),
        ]);
    }

    /**
     * Loans in a time (json), drafts included (shown hatched), cancelled ones not.
     */
    public function loans(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json($this->loansIn($from, $to)->map(fn (Loan $loan) => $loan->summary()));
    }

    /**
     * Timeline (json): one row per item with a bar per loan. Without search only items with loans in
     * the time; with search also the matching items without loans.
     */
    public function timeline(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $search = trim((string) $request->query('search'));
        $categoryId = $request->integer('category');

        $loans = $this->loansIn($from, $to)->load('positions.item', 'positions.children.item')->keyBy('id');
        $bars = [];
        foreach ($loans as $loan) {
            foreach ($loan->positions as $position) {
                $bars[$position->item_id][] = $loan->id;
                // contents of a box are taken along: they get the bar of the loan too
                foreach ($position->children as $child) {
                    $bars[$child->item_id][] = $loan->id;
                }
            }
        }

        $items = Item::with('category')
            ->when($search === '', fn (Builder $query) => $query->whereIn('id', array_keys($bars)))
            ->when($search !== '', fn (Builder $query) => $query->active()->where(fn (Builder $query) => $query->whereLike('name', "%{$search}%")->orWhereLike('number', "%{$search}%")))
            ->when($categoryId, fn (Builder $query) => $query->whereIn('category_id', Category::where('parent_id', $categoryId)->pluck('id')->push($categoryId)))
            ->orderBy('name')->limit(200)->get();

        return response()->json($items->map(fn (Item $item) => [
            ...$item->only(['id', 'number', 'name']),
            'loans' => array_map(fn (int $id) => $loans[$id]->summary(), array_values(array_unique($bars[$item->id] ?? []))),
        ]));
    }

    /**
     * Tab "Verleih" of an item (json): its loans of the coming weeks for the timeline, and all its
     * loans as list.
     */
    public function item(Item $item, Availability $availability): JsonResponse
    {
        $loanIds = LoanItem::where('item_id', $item->id)->orWhere('issued_item_id', $item->id)
            // bookings of a box it lies in now; once issued, the contents are rows of their own
            ->orWhere(fn (Builder $query) => $query->whereIn('item_id', $availability->boxesAround($item))->whereHas('loan', fn (Builder $query) => $query->whereIn('status', [LoanStatus::Draft, LoanStatus::Confirmed])))
            ->pluck('loan_id');
        $loans = Loan::whereIn('id', $loanIds)->with('responsible', 'responsibleUser', 'borrower')->orderByDesc('pickup_at')->limit(100)->get();

        return response()->json([
            'from' => now()->startOfWeek()->subWeek()->toDateString(),
            'to' => now()->startOfWeek()->addWeeks(7)->toDateString(),
            'loans' => $loans->map(fn (Loan $loan) => $loan->summary()),
        ]);
    }

    /**
     * Loans that overlap a time, without cancelled ones.
     *
     * @return Collection<int, Loan>
     */
    private function loansIn(Carbon $from, Carbon $to)
    {
        return Loan::where('status', '!=', LoanStatus::Cancelled)
            ->where('pickup_at', '<', $to)
            ->where(fn (Builder $query) => $query->where('return_at', '>', $from)->orWhere(fn (Builder $query) => $query->overdue()))
            ->with('responsible', 'responsibleUser', 'borrower')
            ->orderBy('pickup_at')->get();
    }

    /**
     * Time of the request ("from", "to" as dates), at most MAX_DAYS long.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request): array
    {
        $validated = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after:from']]);
        $from = Carbon::parse($validated['from'])->startOfDay();
        $to = Carbon::parse($validated['to'])->endOfDay();

        return [$from, $to->min($from->copy()->addDays(self::MAX_DAYS))];
    }
}
