<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\DeleteLocation;
use App\Actions\Inventory\SaveLocation;
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\Location;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Locations: tree, detail, create, change, delete.
 */
class LocationController extends Controller
{
    /**
     * Display all locations as tree. Sorting by full code puts every location after its parent.
     */
    public function index(): Response
    {
        return Inertia::render('Inventory/Locations/Index', [
            'locations' => Location::withCount(['items' => fn ($query) => $query->active()])->orderBy('full_code')->get()
                ->map(fn (Location $location) => [
                    ...$location->summary(),
                    'parent_id' => $location->parent_id,
                    'description' => $location->description,
                    'items_count' => $location->items_count,
                ]),
        ]);
    }

    /**
     * Display a location with the locations and items in it.
     */
    public function show(Location $location): Response
    {
        $path = [];
        for ($parent = $location->parent; $parent !== null; $parent = $parent->parent) {
            array_unshift($path, $parent->summary());
        }

        return Inertia::render('Inventory/Locations/Show', [
            'location' => [...$location->summary(), 'parent_id' => $location->parent_id, 'description' => $location->description],
            'path' => $path,
            'children' => $location->children()->withCount(['items' => fn ($query) => $query->active()])->get()
                ->map(fn (Location $child) => [...$child->summary(), 'items_count' => $child->items_count]),
            'items' => $location->items()->active()->withCount('contents')->orderBy('name')->get()
                ->map(fn (Item $item) => [
                    ...$item->only(['id', 'number', 'name', 'quantity', 'unit', 'is_container', 'contents_count']),
                    'kind' => $item->kind->value,
                ]),
            // for moving the location to another parent
            'locations' => fn () => request()->user()->can('locations.manage')
                ? Location::orderBy('full_code')->get()->map(fn (Location $other) => $other->summary())
                : [],
        ]);
    }

    /**
     * Create a location.
     */
    public function store(Request $request, SaveLocation $saveLocation): RedirectResponse
    {
        $location = $saveLocation->handle(null, $this->validated($request));

        return back()->with('success', "{$location->full_code} wurde angelegt.");
    }

    /**
     * Change a location.
     */
    public function update(Request $request, Location $location, SaveLocation $saveLocation): RedirectResponse
    {
        $saveLocation->handle($location, $this->validated($request));

        return back()->with('success', "{$location->full_code} wurde gespeichert.");
    }

    /**
     * Delete a location and everything below it.
     */
    public function destroy(Location $location, DeleteLocation $deleteLocation): RedirectResponse
    {
        $deleteLocation->handle($location);

        return to_route('inventory.locations.index')->with('success', "{$location->full_code} wurde gelöscht.");
    }

    /**
     * @return array{parent_id: int|null, code: string, name: string|null, description: string|null}
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:locations,id'],
            // letters and digits only, "-" and "." join the codes of the path
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9]+$/'],
            'name' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
        ], ['code.regex' => 'Das Kürzel besteht nur aus Buchstaben und Ziffern.']) + ['parent_id' => null, 'name' => null, 'description' => null];
    }
}
