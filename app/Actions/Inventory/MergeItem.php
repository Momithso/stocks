<?php

namespace App\Actions\Inventory;

use App\Enums\MovementType;
use App\Enums\RetireReason;
use App\Models\Item;
use App\Models\ItemMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Book a split off part of a bulk item back to the item it came from (the opposite of splitting
 * in MoveItem), e.g. tape that comes back from a party. The quantity goes back to the origin; a
 * part that is completely booked back is retired as "zurückgebucht" and keeps its history.
 */
class MergeItem
{
    /**
     * @param  int|null  $quantity  amount to book back, null for everything
     * @return Item the origin
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Item $part, ?int $quantity = null, ?string $note = null): Item
    {
        $origin = $part->splitFrom;
        $message = match (true) {
            $origin === null => "{$part->number} wurde nicht abgeteilt.",
            $part->isRetired() => "{$part->number} ist ausgemustert.",
            $origin->isRetired() => "{$origin->number} ist ausgemustert, dorthin kann nichts zurück.",
            default => null,
        };
        if ($message !== null) {
            throw ValidationException::withMessages(['item' => $message]);
        }

        $quantity ??= $part->quantity;
        if ($quantity < 1 || $quantity > $part->quantity) {
            throw ValidationException::withMessages(['quantity' => "Die Menge muss zwischen 1 und {$part->quantity} liegen."]);
        }

        return DB::transaction(function () use ($actor, $part, $origin, $quantity, $note) {
            $from = $part->place();
            $to = $origin->place();

            $origin->increment('quantity', $quantity);
            ItemMovement::record($origin, MovementType::Merge, $from, $to, $actor, $quantity, trim("Von {$part->number} zurückgebucht. ".$note));

            if ($quantity < $part->quantity) {
                $part->decrement('quantity', $quantity);
            } else {
                $part->forceFill(['retired_at' => now(), 'retired_reason' => RetireReason::Merged, 'retired_note' => "Zurück zu {$origin->number}"]);
                $part->setPlace(null);
                $part->save();
            }
            ItemMovement::record($part, MovementType::Merge, $from, $to, $actor, $quantity, trim("Zurück zu {$origin->number}. ".$note));

            return $origin;
        });
    }
}
