<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Change of the condition of an item with its reason and optional photos.
 *
 * @property int $id
 * @property int $item_id
 * @property string $from_condition name of the condition before
 * @property string $to_condition name of the condition after
 * @property string $reason
 * @property int|null $user_id
 * @property int|null $loan_id loan the change belongs to (loan module, phase 3)
 * @property Carbon $created_at
 */
class ItemConditionChange extends Model
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
        'from_condition',
        'to_condition',
        'reason',
        'user_id',
        'loan_id',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ItemPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(ItemPhoto::class, 'condition_change_id')->orderBy('id');
    }
}
