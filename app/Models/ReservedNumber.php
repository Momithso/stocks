<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An item number printed on a stock label before the item exists. Scanning it opens the form
 * "Gegenstand anlegen" with exactly this number; then the reservation is used.
 *
 * Released numbers are never given out again, so an old label never points to another item.
 *
 * @property int $id
 * @property string $number
 * @property int|null $created_by
 * @property Carbon|null $used_at
 * @property Carbon|null $released_at
 */
class ReservedNumber extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'number',
        'created_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Reservations that are neither used nor released.
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNull('used_at')->whereNull('released_at');
    }

    /**
     * Find the open reservation of a number. Case and hyphens do not matter.
     */
    public static function findOpen(string $input): ?self
    {
        $normalized = preg_replace('/[\s-]/', '', mb_strtoupper(trim($input)));
        if ($normalized === '') {
            return null;
        }

        return self::query()->open()->whereRaw("REPLACE(number, '-', '') = ?", [$normalized])->first();
    }
}
