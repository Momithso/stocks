<?php

namespace App\Models;

use App\Enums\LocationType;
use App\Support\Audit\AuditSubject;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A place in the tree address → room → shelf → compartment.
 *
 * The full code joins the codes of the path ("EU-014-R03.4"). It is stored for search and
 * sorting and recomputed by App\Actions\Inventory\SaveLocation for the whole subtree.
 *
 * @property int $id
 * @property int|null $parent_id
 * @property LocationType $type
 * @property string $code
 * @property string|null $name
 * @property string|null $description
 * @property string $full_code
 */
class Location extends Model implements Auditable, AuditSubject
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    use \OwenIt\Auditing\Auditable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'parent_id',
        'type',
        'code',
        'name',
        'description',
        'full_code',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LocationType::class,
        ];
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'parent_id');
    }

    /**
     * @return HasMany<Location, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Location::class, 'parent_id')->orderBy('code');
    }

    /**
     * Items that lie directly at this location (not in a box).
     *
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'location_id');
    }

    /**
     * Build the full code of a location from the full code of its parent.
     */
    public static function fullCodeOf(?Location $parent, LocationType $type, string $code): string
    {
        return $parent ? $parent->full_code.$type->separator().$code : $code;
    }

    /**
     * Ids of this location and of all locations below it.
     *
     * @return list<int>
     */
    public function subtreeIds(): array
    {
        $ids = [$this->id];
        $level = [$this->id];

        // the tree has at most four levels, so this runs at most four queries
        while ($level) {
            $level = Location::whereIn('parent_id', $level)->pluck('id')->all();
            $ids = [...$ids, ...$level];
        }

        return $ids;
    }

    /**
     * Data of the location for the frontend.
     *
     * @return array{id: int, type: string, type_label: string, code: string, name: string|null, full_code: string}
     */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'code' => $this->code,
            'name' => $this->name,
            'full_code' => $this->full_code,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public static function auditTypeLabel(): string
    {
        return 'Standort';
    }

    /**
     * {@inheritDoc}
     */
    public static function auditName(array $attributes): string
    {
        return trim(($attributes['full_code'] ?? '').' '.($attributes['name'] ?? ''));
    }

    /**
     * {@inheritDoc}
     */
    public static function auditFieldLabels(): array
    {
        return [
            'parent_id' => 'Liegt in',
            'type' => 'Ebene',
            'code' => 'Kürzel',
            'name' => 'Name',
            'description' => 'Beschreibung',
            'full_code' => 'Kennziffer',
        ];
    }
}
