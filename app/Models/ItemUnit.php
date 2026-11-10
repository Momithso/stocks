<?php

namespace App\Models;

use App\Support\Audit\AuditSubject;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A unit of bulk items, e.g. "Stück" or "Meter". Admins manage the list in the settings
 * (permission inventory.options.manage).
 *
 * Items store the name of their unit (items.unit), so the movement log and the history stay
 * readable. Renaming a unit renames it at the items, see App\Http\Controllers\Inventory\UnitController.
 *
 * @property int $id
 * @property string $name
 * @property bool $is_consumable items with this unit are consumables and can be marked as used up
 */
class ItemUnit extends Model implements Auditable, AuditSubject
{
    use \OwenIt\Auditing\Auditable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'is_consumable',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_consumable' => 'boolean',
        ];
    }

    /**
     * Check if a unit (by its name, as items store it) marks consumables.
     */
    public static function isConsumable(?string $name): bool
    {
        return $name !== null && self::where('name', $name)->where('is_consumable', true)->exists();
    }

    /**
     * Number of items with this unit.
     */
    public function itemsCount(): int
    {
        return Item::where('unit', $this->name)->count();
    }

    /**
     * All units as options for select fields.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return self::orderBy('id')->pluck('name')->map(fn (string $name) => ['value' => $name, 'label' => $name])->all();
    }

    /**
     * {@inheritDoc}
     */
    public static function auditTypeLabel(): string
    {
        return 'Einheit';
    }

    /**
     * {@inheritDoc}
     */
    public static function auditName(array $attributes): string
    {
        return (string) ($attributes['name'] ?? '');
    }

    /**
     * {@inheritDoc}
     */
    public static function auditFieldLabels(): array
    {
        return [
            'name' => 'Name',
            'is_consumable' => 'Verbrauchsmaterial',
        ];
    }
}
