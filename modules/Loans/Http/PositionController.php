<?php

namespace Modules\Loans\Http;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Support\Inventory\CodeLookup;
use App\Support\Surface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Loans\Actions\ChangePositions;
use Modules\Loans\Models\Loan;
use Modules\Loans\Models\LoanItem;
use Modules\Loans\Support\Availability;

/**
 * Positions of a loan: added by search or scan, changed, removed.
 */
class PositionController extends Controller
{
    public function __construct(private readonly ChangePositions $positions, private readonly CodeLookup $codes) {}

    /**
     * Add an item by its id or by a scanned code.
     */
    public function store(Request $request, Loan $loan): RedirectResponse
    {
        $validated = $request->validate([
            'item_id' => ['nullable', 'integer', 'exists:items,id'],
            'code' => ['nullable', 'string', 'max:200'],
            'quantity' => ['nullable', 'integer', 'min:1'],
        ]);

        $item = isset($validated['item_id']) ? Item::findOrFail($validated['item_id']) : $this->codes->find((string) ($validated['code'] ?? ''));
        if (! $item instanceof Item) {
            throw ValidationException::withMessages(['item' => $this->codes->notFoundMessage((string) ($validated['code'] ?? ''))]);
        }

        $this->positions->add($loan, $item->load('condition'), $validated['quantity'] ?? 1);

        // on the kiosk (no loan page) the page that added it, e.g. the issue
        $target = Surface::link('loans.show', $loan);

        return ($target ? redirect($target) : back())->with('success', "{$item->number} {$item->name} ist in der Buchung.");
    }

    /**
     * Change the quantity of a bulk item.
     */
    public function update(Request $request, Loan $loan, LoanItem $position): RedirectResponse
    {
        $validated = $request->validate(['quantity' => ['required', 'integer', 'min:1']]);
        $this->positions->setQuantity($loan, $position, $validated['quantity']);

        return back();
    }

    /**
     * Take a position out of the loan.
     */
    public function destroy(Loan $loan, LoanItem $position): RedirectResponse
    {
        $this->positions->remove($loan, $position);

        return back()->with('success', "{$position->item->number} ist nicht mehr in der Buchung.");
    }

    /**
     * Search items to add (json): name or number, with the availability in the time of the loan.
     */
    public function search(Request $request, Availability $availability): JsonResponse
    {
        $search = trim((string) $request->query('search'));
        $loan = Loan::find($request->integer('loan'));
        if ($search === '' || $loan === null) {
            return response()->json([]);
        }

        $items = Item::active()->with('condition')
            ->where(fn (Builder $query) => $query->whereLike('name', "%{$search}%")->orWhereLike('number', "%{$search}%"))
            ->orderBy('name')->limit(15)->get();

        return response()->json($items->map(fn (Item $item) => [
            ...$item->only(['id', 'number', 'name', 'quantity', 'unit', 'is_container']),
            'kind' => $item->kind->value,
            'place' => Item::placeSummary($item->place()),
            'check' => $availability->check($item, 1, $loan->pickup_at, $loan->return_at, $loan),
        ]));
    }
}
