<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\MergeItem;
use App\Actions\Inventory\MoveItem;
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Support\Inventory\CodeLookup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Moving items: first the item, then the target, both by code. A hand scanner types the code like
 * a keyboard, so a whole box of material can be put away one scan after the other.
 */
class MoveController extends Controller
{
    /**
     * Display the page for moving by scan.
     */
    public function create(): Response
    {
        return Inertia::render('Inventory/Move');
    }

    /**
     * Move an item to the place of a code. Also used by the dialog on the item page. If the code is
     * the item a part was split off from, the part is booked back (MergeItem).
     */
    public function store(Request $request, CodeLookup $codes, MoveItem $moveItem, MergeItem $mergeItem): RedirectResponse
    {
        $validated = $request->validate([
            'item_id' => ['required', 'integer'],
            'target_code' => ['required', 'string', 'max:50'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $item = Item::findOrFail($validated['item_id']);

        // scanning the item a part was split off from books the part back
        $found = $codes->find($validated['target_code']);
        if ($found instanceof Item && $item->mergeTarget()?->id === $found->id) {
            $origin = $mergeItem->handle($request->user(), $item, $validated['quantity'] ?? null, $validated['note'] ?? null);

            return back()->with('success', "{$item->number} zurück zu {$origin->number} gebucht, dort sind jetzt {$origin->quantity} {$origin->unit}.");
        }

        $target = $codes->findPlace($validated['target_code'])
            ?? throw ValidationException::withMessages(['target_code' => "Zu {$validated['target_code']} gibt es keinen Standort und keine Box."]);

        $moved = $moveItem->handle($request->user(), $item, $target, $validated['quantity'] ?? null, $validated['note'] ?? null);

        $targetCode = Item::placeSummary($target)['code'];

        if ($moved->is($item)) {
            return back()->with('success', "{$item->number} liegt jetzt in {$targetCode}.");
        }

        // the split off part has a new number and needs a label
        return back()
            ->with('success', "{$moved->quantity} {$moved->unit} von {$item->number} liegen jetzt als {$moved->number} in {$targetCode}.")
            ->with('label', ['id' => $moved->id, 'number' => $moved->number]);
    }
}
