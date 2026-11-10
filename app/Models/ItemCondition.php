<?php

namespace App\Models;

use App\Support\Audit\AuditSubject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A condition of items, e.g. "gut" or "defekt". Admins manage the list in the settings (permission inventory.options.manage).
 *
 * The condition of an item only changes with a reason, see App\Actions\Inventory\ChangeItemCondition.
 *
 * @property int $id
 * @property string $name
 * @property bool $is_defect the condition means a damage
 */
class ItemCondition extends Model implements Auditable, AuditSubject
{
    use \OwenIt\Auditing\Auditable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'is_defect',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_defect' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'condition_id');
    }

    /**
     * All conditions as options for select fields, in the order they were created.
     *
     * @return list<array{value: int, label: string}>
     */
    public static function options(): array
    {
        return self::orderBy('id')->get()->map(fn (self $condition) => ['value' => $condition->id, 'label' => $condition->name])->all();
    }

    /**
     * {@inheritDoc}
     */
    public static function auditTypeLabel(): string
    {
        return 'Zustand';
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
            'is_defect' => 'Bedeutet einen Schaden',
        ];
    }
}
