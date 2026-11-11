<?php

namespace Modules\Loans\Http;

use App\Http\Controllers\Controller;
use App\Models\Item;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Loans\Actions\ResolveMissing;
use Modules\Loans\Actions\StowItem;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Models\Loan;
use Modules\Loans\Models\LoanItem;

/**
 * "Rückgaben": loans waiting for the inspection, open defects, and the items that came back but
 * are not put back yet, with putting back by scan.
 */
class ReturnsController extends Controller
{
    /**
     * Display the lists.
     */
    public function index(Request $request): Response
    {
        $loans = fn (LoanStatus $status, bool $openDefects = false) => Loan::where('status', $status)
            ->when($openDefects, fn ($query) => $query->whereNull('defects_closed_at'))
            ->with('responsible', 'responsibleUser', 'borrower')->orderBy('returned_at')->limit(100)->get()
            ->map(fn (Loan $loan) => [...$loan->summary(), 'returned_at' => $loan->returned_at?->toIso8601String()]);

        return Inertia::render('Loans::Returns', [
            'unchecked' => $loans(LoanStatus::ReturnedUnchecked),
            'defects' => $loans(LoanStatus::ClosedWithDefects, true),
            'pending' => StowItem::pending()->with(['item.homeLocation', 'item.homeContainer', 'issuedItem', 'loan'])->orderBy('returned_at')->limit(200)->get()->map(fn (LoanItem $row) => [
                'id' => $row->id,
                'number' => $row->physicalItem()->number,
                'name' => $row->item->name,
                'quantity' => $row->isBulk() ? ($row->returned_quantity ?? $row->quantity) : null,
                'unit' => $row->item->unit,
                'loan' => $row->loan->only(['id', 'number']),
                'returned_at' => $row->returned_at->toIso8601String(),
                // a split off part goes back to its origin, everything else to its home
                'merge_to' => $row->issued_item_id ? $row->item->number : null,
                'home' => Item::placeSummary($row->item->home()),
            ]),
            'selected' => $request->integer('row') ?: null,
        ]);
    }

    /**
     * Put an item back: scanned place, or its origin for a split off part.
     */
    public function stow(Request $request, StowItem $stowItem): RedirectResponse
    {
        $validated = $request->validate([
            'row_id' => ['required', 'integer', 'exists:loan_items,id'],
            'code' => ['nullable', 'string', 'max:200'],
        ]);

        $message = $stowItem->handle($request->user(), LoanItem::findOrFail($validated['row_id']), $validated['code'] ?? null);

        return back()->with('success', $message);
    }

    /**
     * Something missing turned up again. A single item goes on to the list for putting back.
     */
    public function found(Request $request, Loan $loan, LoanItem $row, ResolveMissing $resolveMissing): RedirectResponse
    {
        abort_unless($row->loan_id === $loan->id, 404);
        $message = $resolveMissing->found($request->user(), $row);

        return StowItem::pending()->whereKey($row->id)->exists()
            ? to_route('loans.returns', ['row' => $row->id])->with('success', $message)
            : back()->with('success', $message);
    }
}
