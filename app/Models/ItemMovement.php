<?php

namespace App\Models;

use App\Enums\MovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Row of the movement log: where an item came from, where it went, who moved it.
 *
 * Rows are only created, never changed. A mistake is corrected with a new movement.
 *
 * @property int $id
 * @property int $item_id
 * @property MovementType $type
 * @property int $quantity
 * @property int|null $from_location_id
 * @property int|null $from_container_id
 * @property int|null $to_location_id
 * @property int|null $to_container_id
 * @property int|null $user_id
 * @property string|null $note
 * @property Carbon $created_at
 */
class ItemMovement extends Model
{
    /**
     * Rows are never updated.
     *
     * @var string|null
     */
    public const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'quantity',
        'from_location_id',
        'from_container_id',
        'to_location_id',
        'to_container_id',
        'user_id',
        'note',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MovementType::class,
        ];
    }

    /**
     * Write a row for an item that moves from one place to another.
     */
    public static function record(Item $item, MovementType $type, Location|Item|null $from, Location|Item|null $to, ?User $user, ?int $quantity = null, ?string $note = null): self
    {
        return $item->movements()->create([
            'type' => $type,
            'quantity' => $quantity ?? $item->quantity,
            'from_location_id' => $from instanceof Location ? $from->id : null,
            'from_container_id' => $from instanceof Item ? $from->id : null,
            'to_location_id' => $to instanceof Location ? $to->id : null,
            'to_container_id' => $to instanceof Item ? $to->id : null,
            'user_id' => $user?->id,
            'note' => $note,
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function fromContainer(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'from_container_id');
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function toContainer(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'to_container_id');
    }
}
