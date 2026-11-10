<?php

namespace App\Actions\Inventory;

use App\Enums\ItemKind;
use App\Enums\MovementType;
use App\Models\Item;
use App\Models\ItemMovement;
use App\Models\Location;
use App\Models\User;
use App\Support\Inventory\ItemNumbers;
use App\Support\Inventory\Placement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Move an item to a location or into a box. The contents of a box move with it.
 *
 * Moving a part of a bulk item splits it: the part becomes a new item with its own number and a
 * copy of the photos at the new place, the rest stays. Both get a row in the movement log.
 */
class MoveItem
{
    public function __construct(private readonly ItemNumbers $numbers) {}

    /**
     * @param  int|null  $quantity  part of a bulk item to move, null for everything
     * @return Item the moved item: the item itself or the new part
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Item $item, Location|Item $target, ?int $quantity = null, ?string $note = null): Item
    {
        if ($item->isRetired()) {
            throw ValidationException::withMessages(['item' => "{$item->number} ist ausgemustert."]);
        }
        Placement::check($item, $target, 'target');
        if ($item->place()?->is($target)) {
            throw ValidationException::withMessages(['target' => "{$item->number} liegt schon dort."]);
        }

        $quantity ??= $item->quantity;
        if ($quantity < 1 || $quantity > $item->quantity) {
            throw ValidationException::withMessages(['quantity' => "Die Menge muss zwischen 1 und {$item->quantity} liegen."]);
        }

        return DB::transaction(function () use ($actor, $item, $target, $quantity, $note) {
            $from = $item->place();

            if ($item->kind === ItemKind::Bulk && $quantity < $item->quantity) {
                return $this->split($actor, $item, $from, $target, $quantity, $note);
            }

            $item->setPlace($target);
            $item->save();
            ItemMovement::record($item, MovementType::Move, $from, $target, $actor, note: $note);

            return $item;
        });
    }

    /**
     * Split a part off a bulk item and put it at the target.
     */
    private function split(User $actor, Item $item, Location|Item|null $from, Location|Item $target, int $quantity, ?string $note): Item
    {
        $part = $item->replicate(['number', 'location_id', 'container_id', 'home_location_id', 'home_container_id']);
        $part->number = $this->numbers->next();
        $part->quantity = $quantity;
        $part->split_from_id = $item->id;
        $part->setPlace($target);
        $part->save();

        // the part shows the same photos; a photo S3 can not copy is left out, the move still counts
        foreach ($item->photos as $photo) {
            $photo->copyTo($part);
        }

        $item->decrement('quantity', $quantity);

        $splitNote = trim("{$quantity} {$item->unit} als {$part->number} abgeteilt. ".$note);
        ItemMovement::record($item, MovementType::Split, $from, $target, $actor, $quantity, $splitNote);
        ItemMovement::record($part, MovementType::Split, $from, $target, $actor, $quantity, trim("Abgeteilt von {$item->number}. ".$note));

        return $part;
    }
}
