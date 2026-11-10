<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Categories with one level of sub categories. Plain create, change and delete, so no actions.
 */
class CategoryController extends Controller
{
    /**
     * Display the categories.
     */
    public function index(): Response
    {
        return Inertia::render('Inventory/Categories/Index', [
            'categories' => Category::whereNull('parent_id')->withCount('items')
                ->with(['children' => fn ($query) => $query->withCount('items')])
                ->orderBy('name')->get()
                ->map(fn (Category $category) => [
                    ...$category->only(['id', 'name', 'items_count']),
                    'children' => $category->children->map(fn (Category $child) => $child->only(['id', 'name', 'parent_id', 'items_count'])),
                ]),
        ]);
    }

    /**
     * Create a category.
     */
    public function store(Request $request): RedirectResponse
    {
        $category = Category::create($this->validated($request, null));

        return back()->with('success', "{$category->name} wurde angelegt.");
    }

    /**
     * Change a category.
     */
    public function update(Request $request, Category $category): RedirectResponse
    {
        $validated = $this->validated($request, $category);
        if ($validated['parent_id'] !== null && $category->children()->exists()) {
            throw ValidationException::withMessages(['parent_id' => 'Eine Kategorie mit Unterkategorien kann keine Unterkategorie werden.']);
        }

        $category->update($validated);

        return back()->with('success', "{$category->name} wurde gespeichert.");
    }

    /**
     * Delete a category without items and sub categories.
     */
    public function destroy(Category $category): RedirectResponse
    {
        if ($category->items()->exists() || $category->children()->exists()) {
            throw ValidationException::withMessages(['category' => "{$category->name} hat noch Gegenstände oder Unterkategorien."]);
        }

        $category->delete();

        return back()->with('success', "{$category->name} wurde gelöscht.");
    }

    /**
     * @return array{name: string, parent_id: int|null}
     */
    private function validated(Request $request, ?Category $category): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            // only top level categories can be a parent (two levels)
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->whereNull('parent_id')->whereNot('id', $category?->id)],
        ]) + ['parent_id' => null];
    }
}
