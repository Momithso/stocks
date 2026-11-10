<?php

namespace App\Actions\Labels;

use App\Models\ReservedNumber;
use App\Models\User;
use App\Support\Inventory\ItemNumbers;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reserve item numbers for stock labels. They come from the same counter as all item numbers, so
 * they never collide with an item.
 */
class ReserveNumbers
{
    /**
     * Most numbers reserved at once.
     */
    public const MAX = 200;

    public function __construct(private readonly ItemNumbers $numbers) {}

    /**
     * @return Collection<int, ReservedNumber>
     */
    public function handle(User $actor, int $count): Collection
    {
        return DB::transaction(fn () => collect(range(1, min($count, self::MAX)))
            ->map(fn () => ReservedNumber::create(['number' => $this->numbers->next(), 'created_by' => $actor->id])));
    }
}
