<?php

namespace App\Actions\Inventory;

use App\Enums\MovementType;
use App\Models\Item;
use App\Models\ItemPhoto;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Delete an item that was created by mistake. Items that were moved, split or are used as box
 * are retired instead, so their history stays.
 */
class DeleteItem
{
    /**
     * @throws ValidationException
     */
    public function handle(Item $item): void
    {
        $hasHistory = $item->movements()->where('type', '!=', MovementType::Create)->exists()
            || $item->conditionChanges()->exists();
        $isUsed = Item::where('container_id', $item->id)->orWhere('home_container_id', $item->id)->orWhere('split_from_id', $item->id)->exists();

        if ($hasHistory || $isUsed) {
            throw ValidationException::withMessages(['item' => "{$item->number} hat schon eine Geschichte und kann nur ausgemustert werden."]);
        }

        $paths = ItemPhoto::where('item_id', $item->id)->pluck('path')->all();
        $item->delete();
        if ($paths) {
            Storage::disk(ItemPhoto::DISK)->delete($paths);
        }
    }
}
