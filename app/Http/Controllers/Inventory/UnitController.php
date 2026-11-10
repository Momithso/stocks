<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\ItemUnit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The list of units of bulk items. Plain create, change and delete, so no actions.
 */
class UnitController extends Controller
{
    /**
     * Display the units.
     */
    public function index(): Response
    {
        return Inertia::render('Inventory/Units/Index', [
            'units' => ItemUnit::orderBy('id')->get()->map(fn (ItemUnit $unit) => [
                'id' => $unit->id,
                'name' => $unit->name,
                'is_consumable' => $unit->is_consumable,
                'items_count' => $unit->itemsCount(),
            ]),
        ]);
    }

    /**
     * Create a unit.
     */
    public function store(Request $request): RedirectResponse
    {
        $unit = ItemUnit::create($this->validated($request, null));

        return back()->with('success', "Die Einheit „{$unit->name}“ wurde angelegt.");
    }

    /**
     * Rename a unit, also at the items that have it.
     */
    public function update(Request $request, ItemUnit $unit): RedirectResponse
    {
        $validated = $this->validated($request, $unit);

        DB::transaction(function () use ($unit, $validated) {
            Item::where('unit', $unit->name)->update(['unit' => $validated['name']]);
            $unit->update($validated);
        });

        return back()->with('success', "Die Einheit „{$unit->name}“ wurde gespeichert.");
    }

    /**
     * Delete a unit that no item has.
     */
    public function destroy(ItemUnit $unit): RedirectResponse
    {
        if ($unit->itemsCount() > 0) {
            throw ValidationException::withMessages(['unit' => "Gegenstände haben noch die Einheit „{$unit->name}“."]);
        }

        $unit->delete();

        return back()->with('success', "Die Einheit „{$unit->name}“ wurde gelöscht.");
    }

    /**
     * @return array{name: string, is_consumable: bool}
     */
    private function validated(Request $request, ?ItemUnit $unit): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:20', Rule::unique('item_units')->ignore($unit)],
            'is_consumable' => ['boolean'],
        ]) + ['is_consumable' => false];
    }
}
