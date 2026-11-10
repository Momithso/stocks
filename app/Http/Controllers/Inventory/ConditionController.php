<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\ItemCondition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The list of conditions of items. Plain create, change and delete, so no actions.
 *
 * The history of condition changes stores names, so it stays readable after a rename or delete.
 */
class ConditionController extends Controller
{
    /**
     * Display the conditions.
     */
    public function index(): Response
    {
        return Inertia::render('Inventory/Conditions/Index', [
            'conditions' => ItemCondition::withCount('items')->orderBy('id')->get(['id', 'name', 'is_defect']),
        ]);
    }

    /**
     * Create a condition.
     */
    public function store(Request $request): RedirectResponse
    {
        $condition = ItemCondition::create($this->validated($request, null));

        return back()->with('success', "Der Zustand „{$condition->name}“ wurde angelegt.");
    }

    /**
     * Change a condition.
     */
    public function update(Request $request, ItemCondition $condition): RedirectResponse
    {
        $condition->update($this->validated($request, $condition));

        return back()->with('success', "Der Zustand „{$condition->name}“ wurde gespeichert.");
    }

    /**
     * Delete a condition that no item has. At least one condition stays.
     */
    public function destroy(ItemCondition $condition): RedirectResponse
    {
        $message = match (true) {
            $condition->items()->exists() => "Gegenstände haben noch den Zustand „{$condition->name}“. Ändere erst ihren Zustand.",
            ItemCondition::count() === 1 => 'Es muss mindestens einen Zustand geben.',
            default => null,
        };
        if ($message !== null) {
            throw ValidationException::withMessages(['condition' => $message]);
        }

        $condition->delete();

        return back()->with('success', "Der Zustand „{$condition->name}“ wurde gelöscht.");
    }

    /**
     * @return array{name: string, is_defect: bool}
     */
    private function validated(Request $request, ?ItemCondition $condition): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('item_conditions')->ignore($condition)],
            'is_defect' => ['boolean'],
        ]) + ['is_defect' => false];
    }
}
