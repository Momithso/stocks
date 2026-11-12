<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\ChangeItemCondition;
use App\Actions\Inventory\CreateItem;
use App\Actions\Inventory\DeleteItem;
use App\Actions\Inventory\MergeItem;
use App\Actions\Inventory\MoveItem;
use App\Actions\Inventory\RetireItem;
use App\Actions\Inventory\UpdateItem;
use App\Enums\ItemKind;
use App\Enums\LocationType;
use App\Enums\RetireReason;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Item;
use App\Models\ItemCondition;
use App\Models\ItemConditionChange;
use App\Models\ItemMovement;
use App\Models\ItemPhoto;
use App\Models\ItemUnit;
use App\Models\Location;
use App\Models\ReservedNumber;
use App\Support\Extensions\ItemExtensions;
use App\Support\Inventory\CodeLookup;
use App\Support\Labels\ShortLink;
use App\Support\Surface;
use App\Support\Table\ItemsTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Items: list, detail, create, edit, condition, retire, delete.
 */
class ItemController extends Controller
{
    public function __construct(private readonly CodeLookup $codes, private readonly ItemExtensions $extensions) {}

    /**
     * Display all items.
     */
    public function index(Request $request): Response|JsonResponse
    {
        return $this->list($request, false);
    }

    /**
     * Display the items without place or away from their home.
     */
    public function misplaced(Request $request): Response|JsonResponse
    {
        return $this->list($request, true);
    }

    /**
     * Display the form for a new item. With "number", the item gets the number of a stock label.
     */
    public function create(Request $request): Response
    {
        return $this->form(null, $request->query('place'), ReservedNumber::findOpen((string) $request->query('number')));
    }

    /**
     * Create an item.
     */
    public function store(Request $request, CreateItem $createItem): RedirectResponse
    {
        $validated = $request->validate([
            ...$this->rules(),
            'condition_id' => ['required', 'integer', 'exists:item_conditions,id'],
            'place_code' => ['nullable', 'string', 'max:50'],
            'photos' => ['array', 'max:'.ItemPhoto::MAX_PER_ITEM],
            'photos.*' => ItemPhotoController::fileRules(),
            'reserved_number' => ['nullable', 'string', 'max:30'],
        ]);

        $reserved = null;
        if (! empty($validated['reserved_number'])) {
            $reserved = ReservedNumber::findOpen($validated['reserved_number'])
                ?? throw ValidationException::withMessages(['reserved_number' => "Die Nummer {$validated['reserved_number']} ist nicht reserviert."]);
        }

        $item = $createItem->handle(
            $request->user(),
            $this->itemData($validated),
            ItemCondition::findOrFail($validated['condition_id']),
            $this->placeOf($validated['place_code'] ?? null, 'place_code'),
            $this->placeOf($validated['home_code'] ?? null, 'home_code'),
            $request->file('photos', []),
            $reserved,
        );

        return to_route('inventory.items.show', $item)->with('success', "{$item->number} wurde angelegt.");
    }

    /**
     * Display an item with its place, contents, photos and history.
     */
    public function show(Request $request, Item $item): Response
    {
        $item->load(['category.parent', 'condition', 'location', 'container', 'homeLocation', 'homeContainer', 'splitFrom', 'photos']);

        return Inertia::render('Inventory/Items/Show', [
            'item' => [
                ...$item->only(['id', 'number', 'name', 'description', 'quantity', 'unit', 'is_container', 'public_contents', 'notes', 'retired_note', 'label_hint']),
                'label_printed_at' => $item->label_printed_at?->toIso8601String(),
                'kind' => $item->kind->value,
                'kind_label' => $item->kind->label(),
                'condition_id' => $item->condition_id,
                'condition' => $item->condition->name,
                'condition_is_defect' => $item->condition->is_defect,
                'category' => $item->category?->fullName(),
                'place' => Item::placeSummary($item->place()),
                'home' => Item::placeSummary($item->home()),
                'location' => Item::placeSummary($item->container ? $item->effectiveLocation() : null),
                'away_from_home' => $item->isAwayFromHome(),
                'split_from' => Item::placeSummary($item->mergeTarget()),
                'can_merge' => ($target = $item->mergeTarget()) !== null && ! $target->isRetired() && ! $item->isRetired(),
                'retired_at' => $item->retired_at?->toIso8601String(),
                'retired_reason' => $item->retired_reason?->label(),
                'photos' => $item->photos->map(fn (ItemPhoto $photo) => ['id' => $photo->id, 'url' => $photo->url()]),
                'contents' => $item->is_container ? $this->contentsTree($item) : [],
            ],
            // notices and tabs of modules, e.g. "ausgegeben an … bis …" and the tab "Verleih"
            'notices' => $this->extensions->notices(collect([$item]), $request->user())[$item->id] ?? [],
            'tabs' => $this->extensions->tabsFor($request->user()),
            'history' => fn () => $this->history($item),
            'conditions' => ItemCondition::options(),
            // "verbraucht" has its own action for consumables
            'retireReasons' => array_values(array_filter(RetireReason::options(), fn (array $option) => ! in_array($option['value'], [RetireReason::Consumed->value, RetireReason::Merged->value], true))),
            'consumable' => $item->kind === ItemKind::Bulk && ItemUnit::isConsumable($item->unit),
            'maxPhotos' => ItemPhoto::MAX_PER_ITEM,
        ]);
    }

    /**
     * Display the form of an item.
     */
    public function edit(Item $item): Response
    {
        return $this->form($item, null);
    }

    /**
     * Change an item.
     */
    public function update(Request $request, Item $item, UpdateItem $updateItem): RedirectResponse
    {
        $validated = $request->validate($this->rules());

        $updateItem->handle($request->user(), $item, $this->itemData($validated), $this->placeOf($validated['home_code'] ?? null, 'home_code'));

        return to_route('inventory.items.show', $item)->with('success', "{$item->number} wurde gespeichert.");
    }

    /**
     * Change the condition of an item, with reason and photos.
     */
    public function changeCondition(Request $request, Item $item, ChangeItemCondition $changeCondition): RedirectResponse
    {
        $validated = $request->validate([
            'condition_id' => ['required', 'integer', 'exists:item_conditions,id'],
            'reason' => ['required', 'string', 'max:2000'],
            'photos' => ['array', 'max:'.ItemPhoto::MAX_PER_ITEM],
            'photos.*' => ItemPhotoController::fileRules(),
            'confirmed' => ['boolean'],
        ]);
        $condition = ItemCondition::findOrFail($validated['condition_id']);
        // a defect condition takes the item from its bookings: ask first
        if ($condition->is_defect && ! $item->condition->is_defect) {
            $this->extensions->ensureConfirmed($item, $request->boolean('confirmed'), 'als defekt markieren');
        }

        $changeCondition->handle($request->user(), $item, $condition, $validated['reason'], $request->file('photos', []));

        return back()->with('success', 'Der Zustand wurde geändert.');
    }

    /**
     * Put an item back at its home.
     */
    public function returnHome(Request $request, Item $item, MoveItem $moveItem): RedirectResponse
    {
        $home = $item->home();
        if ($home === null) {
            throw ValidationException::withMessages(['target' => "{$item->number} hat keinen Stammplatz."]);
        }

        $moveItem->handle($request->user(), $item, $home);

        return back()->with('success', "{$item->number} liegt wieder an seinem Stammplatz.");
    }

    /**
     * Retire an item.
     */
    public function retire(Request $request, Item $item, RetireItem $retireItem): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', Rule::enum(RetireReason::class)],
            'note' => ['nullable', 'string', 'max:2000'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'confirmed' => ['boolean'],
        ]);
        $reason = RetireReason::from($validated['reason']);
        $this->extensions->ensureConfirmed($item, $request->boolean('confirmed'), 'ausbuchen');

        $retireItem->handle($request->user(), $item, $reason, $validated['note'] ?? null, $validated['quantity'] ?? null);

        return back()->with('success', $item->isRetired()
            ? "{$item->number} wurde ausgemustert."
            : "{$validated['quantity']} {$item->unit} von {$item->number} als {$reason->label()} ausgebucht.");
    }

    /**
     * Mark a part of a consumable as used up. Its own action on the item page, so it is quick to
     * reach; only for bulk items whose unit is marked as consumable.
     */
    public function consume(Request $request, Item $item, RetireItem $retireItem): RedirectResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        if ($item->kind !== ItemKind::Bulk || ! ItemUnit::isConsumable($item->unit)) {
            throw ValidationException::withMessages(['quantity' => "{$item->number} ist kein Verbrauchsmaterial."]);
        }
        $this->extensions->ensureConfirmed($item, $request->boolean('confirmed'), 'als verbraucht ausbuchen');

        $retireItem->handle($request->user(), $item, RetireReason::Consumed, $validated['note'] ?? null, $validated['quantity']);

        return back()->with('success', "{$validated['quantity']} {$item->unit} von {$item->number} als verbraucht ausgebucht.");
    }

    /**
     * Book a split off part back to the item it came from.
     */
    public function merge(Request $request, Item $item, MergeItem $mergeItem): RedirectResponse
    {
        $validated = $request->validate(['quantity' => ['nullable', 'integer', 'min:1']]);

        $origin = $mergeItem->handle($request->user(), $item, $validated['quantity'] ?? null);

        return to_route('inventory.items.show', $origin)->with('success', "Zurück zu {$origin->number} gebucht, dort sind jetzt {$origin->quantity} {$origin->unit}.");
    }

    /**
     * Delete an item that was created by mistake.
     */
    public function destroy(Item $item, DeleteItem $deleteItem): RedirectResponse
    {
        $deleteItem->handle($item);

        return to_route('inventory.items.index')->with('success', "{$item->number} wurde gelöscht.");
    }

    /**
     * Find an item or a location by a scanned or typed code (json).
     */
    public function lookup(Request $request): JsonResponse
    {
        $code = (string) $request->query('code');
        $found = $this->codes->find($code);

        if ($found instanceof Item) {
            $found->load(['condition', 'location', 'container', 'photos', 'splitFrom']);

            return response()->json([
                'type' => 'item',
                ...$found->only(['id', 'number', 'name', 'quantity', 'unit', 'is_container', 'split_from_id']),
                'kind' => $found->kind->value,
                'retired' => $found->isRetired(),
                'place' => Item::placeSummary($found->place()),
                'condition_id' => $found->condition_id,
                'condition' => $found->condition->name,
                'condition_is_defect' => $found->condition->is_defect,
                'photo_url' => ($photo = $found->photos->first()) ? Surface::route('inventory.photos.show', $photo) : null,
                'contents_count' => $found->is_container ? $found->contents()->active()->count() : 0,
                'consumable' => $found->kind === ItemKind::Bulk && ItemUnit::isConsumable($found->unit),
                'can_merge' => ($target = $found->mergeTarget()) !== null && ! $target->isRetired() && ! $found->isRetired(),
                'split_from' => Item::placeSummary($found->mergeTarget()),
                'href' => Surface::link('inventory.items.show', $found),
                'notices' => $this->extensions->notices(collect([$found]), $request->user())[$found->id] ?? [],
            ]);
        }

        if ($found instanceof Location) {
            return response()->json([
                ...$found->summary(),
                // the type of the location is "location_type", "type" tells item from location
                'type' => 'location',
                'location_type' => $found->type->value,
                'items_count' => $found->items()->active()->count(),
                'href' => Surface::link('inventory.locations.show', $found),
            ]);
        }

        // number of a stock label without item yet
        if ($reserved = ReservedNumber::findOpen(ShortLink::codeOf($code))) {
            return response()->json([
                'type' => 'reserved',
                'number' => $reserved->number,
                'message' => "{$reserved->number} ist für ein Etikett auf Vorrat reserviert, es gibt noch keinen Gegenstand dazu.",
                'href' => Surface::link('inventory.items.create', ['number' => $reserved->number]),
            ]);
        }

        return response()->json(['message' => $this->codes->notFoundMessage($code)], 404);
    }

    /**
     * Search places for a place field: locations and boxes (json).
     */
    public function places(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search'));
        if ($search === '') {
            return response()->json([]);
        }

        // items never lie directly at an address
        $locations = Location::where('type', '!=', LocationType::Address)
            ->where(fn ($query) => $query->whereLike('full_code', "%{$search}%")->orWhereLike('name', "%{$search}%"))
            ->orderBy('full_code')->limit(8)->get();
        $boxes = Item::active()->where('is_container', true)
            ->where(fn ($query) => $query->whereLike('number', "%{$search}%")->orWhereLike('name', "%{$search}%"))
            ->orderBy('name')->limit(8)->get();

        return response()->json([
            ...$locations->map(fn (Location $location) => Item::placeSummary($location)),
            ...$boxes->map(fn (Item $box) => Item::placeSummary($box)),
        ]);
    }

    /**
     * Numbers for the dashboard tile (json).
     */
    public function stats(): JsonResponse
    {
        return response()->json([
            'items' => Item::active()->count(),
            'misplaced' => Item::active()->misplaced()->count(),
        ]);
    }

    /**
     * Item list, also as live update.
     */
    private function list(Request $request, bool $onlyMisplaced): Response|JsonResponse
    {
        $table = new ItemsTable($request, $onlyMisplaced);
        if ($table->wantsChanges()) {
            return $table->changesResponse();
        }

        return Inertia::render('Inventory/Items/Index', [
            'items' => $table->scroll(),
            'misplaced' => $onlyMisplaced,
            'categories' => Category::options(),
            'conditions' => ItemCondition::options(),
            'filters' => $request->only(['category', 'condition', 'retired', 'unlabeled']),
        ]);
    }

    /**
     * Form for a new item (with an optional place code) or an existing one.
     */
    private function form(?Item $item, ?string $placeCode, ?ReservedNumber $reserved = null): Response
    {
        $item?->load(['homeLocation', 'homeContainer']);

        return Inertia::render('Inventory/Items/Form', [
            'item' => $item ? [
                ...$item->only(['id', 'number', 'name', 'description', 'category_id', 'quantity', 'unit', 'is_container', 'public_contents', 'notes', 'label_hint']),
                'kind' => $item->kind->value,
                'home' => Item::placeSummary($item->home()),
            ] : null,
            'place' => $placeCode ? Item::placeSummary($this->codes->findPlace($placeCode)) : null,
            'reservedNumber' => $reserved?->number,
            'categories' => Category::options(),
            'kinds' => ItemKind::options(),
            'units' => ItemUnit::options(),
            'maxPhotos' => ItemPhoto::MAX_PER_ITEM,
            'conditions' => ItemCondition::options(),
        ]);
    }

    /**
     * Validation rules of the item fields.
     *
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'kind' => ['required', Rule::enum(ItemKind::class)],
            'quantity' => ['required_if:kind,bulk', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'unit' => ['nullable', 'string', 'exists:item_units,name'],
            'is_container' => ['boolean'],
            'public_contents' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'label_hint' => ['nullable', 'string', 'max:100'],
            'home_code' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * Fields of the item itself from the validated input.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function itemData(array $validated): array
    {
        return [
            ...array_intersect_key($validated, array_flip(['name', 'description', 'category_id', 'kind', 'unit', 'notes', 'label_hint'])),
            'quantity' => $validated['quantity'] ?? 1,
            'is_container' => $validated['is_container'] ?? false,
            'public_contents' => $validated['public_contents'] ?? true,
        ];
    }

    /**
     * Find the place of a code, null for an empty code.
     *
     * @throws ValidationException
     */
    private function placeOf(?string $code, string $field): Location|Item|null
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        return $this->codes->findPlace($code)
            ?? throw ValidationException::withMessages([$field => $this->codes->notFoundMessage($code, place: true)]);
    }

    /**
     * Contents of a box as tree, boxes in boxes included.
     *
     * @return list<array<string, mixed>>
     */
    private function contentsTree(Item $box, int $depth = 0): array
    {
        // the limit only protects against broken data, boxes are never nested this deep
        if ($depth > 20) {
            return [];
        }

        return $box->contents()->get()->map(fn (Item $item) => [
            ...$item->only(['id', 'number', 'name', 'quantity', 'unit', 'is_container']),
            'kind' => $item->kind->value,
            'contents' => $item->is_container ? $this->contentsTree($item, $depth + 1) : [],
        ])->all();
    }

    /**
     * Movements and condition changes of an item, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function history(Item $item): array
    {
        $movements = $item->movements()->with(['user', 'fromLocation', 'fromContainer', 'toLocation', 'toContainer'])->limit(100)->get()
            ->map(fn (ItemMovement $movement) => [
                'key' => "movement-{$movement->id}",
                'at' => $movement->created_at->toIso8601String(),
                'title' => ucfirst($movement->type->label()),
                'from' => Item::placeSummary($movement->fromLocation ?? $movement->fromContainer),
                'to' => Item::placeSummary($movement->toLocation ?? $movement->toContainer),
                'quantity' => $item->kind === ItemKind::Bulk ? $movement->quantity : null,
                'text' => $movement->note,
                'user' => $movement->user?->name,
                'photos' => [],
                'delete_url' => route('inventory.items.movements.destroy', [$item, $movement], absolute: false),
            ]);

        $changes = $item->conditionChanges()->with(['user', 'photos'])->limit(100)->get()
            ->map(fn (ItemConditionChange $change) => [
                'key' => "condition-{$change->id}",
                'at' => $change->created_at->toIso8601String(),
                'title' => "Zustand {$change->from_condition} → {$change->to_condition}",
                'from' => null,
                'to' => null,
                'quantity' => null,
                'text' => $change->reason,
                'user' => $change->user?->name,
                'photos' => $change->photos->map(fn (ItemPhoto $photo) => ['id' => $photo->id, 'url' => $photo->url()]),
                'delete_url' => route('inventory.items.condition-changes.destroy', [$item, $change], absolute: false),
            ]);

        return $movements->concat($changes)->sortByDesc('at')->values()->all();
    }
}
