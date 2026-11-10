<?php

namespace App\Models;

use App\Enums\ItemKind;
use App\Enums\RetireReason;
use App\Support\Audit\AuditSubject;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Something in the inventory: a single piece, a bulk item with a quantity, or a box that holds
 * other items.
 *
 * An item has a current place and optionally a home. Both are a location or a box (never both).
 * Items in a box move with the box without being changed themselves. The current place only
 * changes through App\Actions\Inventory\MoveItem, which writes the movement log.
 *
 * @property int $id
 * @property string $number
 * @property string $name
 * @property string|null $description
 * @property int|null $category_id
 * @property ItemKind $kind
 * @property int $quantity
 * @property string|null $unit
 * @property bool $is_container
 * @property bool $public_contents
 * @property int $condition_id
 * @property string|null $notes
 * @property int|null $location_id
 * @property int|null $container_id
 * @property int|null $home_location_id
 * @property int|null $home_container_id
 * @property int|null $split_from_id
 * @property Carbon|null $retired_at
 * @property RetireReason|null $retired_reason
 * @property string|null $retired_note
 */
class Item extends Model implements Auditable, AuditSubject
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    use \OwenIt\Auditing\Auditable;

    /**
     * The attributes that are mass assignable. Number, place and condition are set by the actions.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'category_id',
        'kind',
        'quantity',
        'unit',
        'is_container',
        'public_contents',
        'notes',
    ];

    /**
     * Attributes to exclude from the audit. The condition is recorded with its name by
     * App\Actions\Inventory\ChangeItemCondition instead of its id.
     *
     * @var list<string>
     */
    protected $auditExclude = [
        'condition_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ItemKind::class,
            'retired_reason' => RetireReason::class,
            'is_container' => 'boolean',
            'public_contents' => 'boolean',
            'quantity' => 'integer',
            'retired_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<ItemCondition, $this>
     */
    public function condition(): BelongsTo
    {
        return $this->belongsTo(ItemCondition::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Box the item lies in.
     *
     * @return BelongsTo<Item, $this>
     */
    public function container(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'container_id');
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function homeLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'home_location_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function homeContainer(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'home_container_id');
    }

    /**
     * Items in this box.
     *
     * @return HasMany<Item, $this>
     */
    public function contents(): HasMany
    {
        return $this->hasMany(Item::class, 'container_id')->orderBy('name');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function splitFrom(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'split_from_id');
    }

    /**
     * Photos of the gallery, the first one is the preview.
     *
     * @return HasMany<ItemPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(ItemPhoto::class)->whereNull('condition_change_id')->orderBy('sort')->orderBy('id');
    }

    /**
     * @return HasMany<ItemMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(ItemMovement::class)->latest('id');
    }

    /**
     * @return HasMany<ItemConditionChange, $this>
     */
    public function conditionChanges(): HasMany
    {
        return $this->hasMany(ItemConditionChange::class)->latest('id');
    }

    /**
     * Items that are not retired.
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('retired_at');
    }

    /**
     * Items without a current place, or with a home they are not at.
     */
    #[Scope]
    protected function misplaced(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query->whereNull('location_id')->whereNull('container_id'))
            ->orWhere(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->whereNotNull('home_location_id')->orWhereNotNull('home_container_id'))
                ->where(fn (Builder $query) => $query
                    ->whereRaw('COALESCE(location_id, 0) <> COALESCE(home_location_id, 0)')
                    ->orWhereRaw('COALESCE(container_id, 0) <> COALESCE(home_container_id, 0)'))));
    }

    /**
     * Current place: a location, a box or nothing.
     */
    public function place(): Location|Item|null
    {
        return $this->location ?? $this->container;
    }

    /**
     * Home: a location, a box or nothing.
     */
    public function home(): Location|Item|null
    {
        return $this->homeLocation ?? $this->homeContainer;
    }

    /**
     * Set the current place. Only used by the actions, which also write the movement log.
     */
    public function setPlace(Location|Item|null $place): void
    {
        $this->location_id = $place instanceof Location ? $place->id : null;
        $this->container_id = $place instanceof Item ? $place->id : null;
        $this->unsetRelation('location')->unsetRelation('container');
    }

    /**
     * Set the home.
     */
    public function setHome(Location|Item|null $home): void
    {
        $this->home_location_id = $home instanceof Location ? $home->id : null;
        $this->home_container_id = $home instanceof Item ? $home->id : null;
        $this->unsetRelation('homeLocation')->unsetRelation('homeContainer');
    }

    /**
     * Check if the item lies somewhere else than its home. Items without home are never misplaced.
     */
    public function isAwayFromHome(): bool
    {
        if ($this->home_location_id === null && $this->home_container_id === null) {
            return false;
        }

        return $this->location_id !== $this->home_location_id || $this->container_id !== $this->home_container_id;
    }

    /**
     * Check if the item lies in the given box, directly or in a box inside it.
     */
    public function isInside(Item $box): bool
    {
        $container = $this->container;

        // a chain of boxes is short; the limit only protects against broken data
        for ($depth = 0; $container !== null && $depth < 100; $depth++) {
            if ($container->is($box)) {
                return true;
            }
            $container = $container->container;
        }

        return false;
    }

    /**
     * Location the item is at: its own, or the one of the outermost box around it.
     */
    public function effectiveLocation(): ?Location
    {
        $item = $this;
        for ($depth = 0; $item->container !== null && $depth < 100; $depth++) {
            $item = $item->container;
        }

        return $item->location;
    }

    /**
     * Check if the item is retired.
     */
    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    /**
     * Data of a place (location or box) for the frontend.
     *
     * @return array{type: string, id: int, code: string, name: string|null}|null
     */
    public static function placeSummary(Location|Item|null $place): ?array
    {
        return match (true) {
            $place instanceof Location => ['type' => 'location', 'id' => $place->id, 'code' => $place->full_code, 'name' => $place->name],
            $place instanceof Item => ['type' => 'container', 'id' => $place->id, 'code' => $place->number, 'name' => $place->name],
            default => null,
        };
    }

    /**
     * {@inheritDoc}
     */
    public static function auditTypeLabel(): string
    {
        return 'Gegenstand';
    }

    /**
     * {@inheritDoc}
     */
    public static function auditName(array $attributes): string
    {
        return trim(($attributes['number'] ?? '').' '.($attributes['name'] ?? ''));
    }

    /**
     * {@inheritDoc}
     */
    public static function auditFieldLabels(): array
    {
        return [
            'number' => 'Artikelnummer',
            'name' => 'Name',
            'description' => 'Beschreibung',
            'category_id' => 'Kategorie',
            'kind' => 'Art',
            'quantity' => 'Menge',
            'unit' => 'Einheit',
            'is_container' => 'Ist Behälter',
            'public_contents' => 'Inhalt öffentlich',
            'condition' => 'Zustand',
            'notes' => 'Notiz',
            'location_id' => 'Standort',
            'container_id' => 'Box',
            'home_location_id' => 'Stammplatz (Standort)',
            'home_container_id' => 'Stammplatz (Box)',
            'retired_at' => 'Ausgemustert am',
            'retired_reason' => 'Grund',
            'retired_note' => 'Notiz zum Ausmustern',
            'history' => 'Verlaufseintrag',
        ];
    }
}
