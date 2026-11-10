<?php

namespace App\Actions\Inventory;

use App\Enums\ItemKind;
use App\Enums\MovementType;
use App\Models\Item;
use App\Models\ItemMovement;
use App\Models\Location;
use App\Models\User;
use App\Support\Inventory\Placement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Change the data and the home of an item. A changed quantity is logged as counting.
 *
 * Place and condition do not change here, see MoveItem and ChangeItemCondition.
 */
class UpdateItem
{
    /**
     * @param  array{name: string, description?: string|null, category_id?: int|null, kind: string, quantity?: int|null, unit?: string|null, is_container?: bool, public_contents?: bool, notes?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Item $item, array $data, Location|Item|null $home): Item
    {
        Placement::check($item, $home, 'home');

        $oldQuantity = $item->quantity;
        $item->fill($data);
        if ($item->kind === ItemKind::Single) {
            $item->quantity = 1;
            $item->unit = null;
        }

        if (! $item->is_container && $item->contents()->exists()) {
            throw ValidationException::withMessages(['is_container' => 'In der Box liegen noch Gegenstände. Räume sie erst aus.']);
        }

        return DB::transaction(function () use ($actor, $item, $home, $oldQuantity) {
            $item->setHome($home);
            $item->save();

            if ($item->quantity !== $oldQuantity) {
                ItemMovement::record($item, MovementType::Count, $item->place(), $item->place(), $actor, note: "Menge {$oldQuantity} → {$item->quantity}");
            }

            return $item;
        });
    }
}
