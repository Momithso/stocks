<?php

namespace App\Actions\Inventory;

use App\Enums\ItemKind;
use App\Enums\MovementType;
use App\Enums\RetireReason;
use App\Models\Item;
use App\Models\ItemMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Take an item out of the inventory. It keeps its number, photos and history, but has no place.
 *
 * Of a bulk item also a part can be taken out, e.g. used up cable ties: the quantity goes down and
 * the movement log keeps how many went out and why. The item itself stays.
 */
class RetireItem
{
    /**
     * @param  int|null  $quantity  part of a bulk item, null for everything
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Item $item, RetireReason $reason, ?string $note, ?int $quantity = null): void
    {
        if ($item->isRetired()) {
            throw ValidationException::withMessages(['item' => "{$item->number} ist schon ausgemustert."]);
        }

        $quantity ??= $item->quantity;
        if ($quantity < 1 || $quantity > $item->quantity) {
            throw ValidationException::withMessages(['quantity' => "Die Menge muss zwischen 1 und {$item->quantity} liegen."]);
        }
        $isPart = $item->kind === ItemKind::Bulk && $quantity < $item->quantity;

        if (! $isPart && $item->contents()->exists()) {
            throw ValidationException::withMessages(['item' => 'In der Box liegen noch Gegenstände. Räume sie erst aus.']);
        }

        DB::transaction(function () use ($actor, $item, $reason, $note, $quantity, $isPart) {
            $from = $item->place();
            // used up consumables get their own kind of row, other reasons go into the note
            $type = $reason === RetireReason::Consumed ? MovementType::Consume : MovementType::Retire;
            $logNote = $type === MovementType::Consume ? $note : trim($reason->label().': '.trim((string) $note), ': ');

            if ($isPart) {
                $item->decrement('quantity', $quantity);
                ItemMovement::record($item, $type, $from, null, $actor, $quantity, $logNote);

                return;
            }

            $item->forceFill(['retired_at' => now(), 'retired_reason' => $reason, 'retired_note' => $note]);
            $item->setPlace(null);
            $item->save();

            ItemMovement::record($item, $type, $from, null, $actor, $quantity, $logNote);
        });
    }
}
