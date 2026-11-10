<?php

namespace App\Actions\Inventory;

use App\Enums\ItemKind;
use App\Enums\MovementType;
use App\Models\Item;
use App\Models\ItemCondition;
use App\Models\ItemMovement;
use App\Models\ItemPhoto;
use App\Models\Location;
use App\Models\ReservedNumber;
use App\Models\User;
use App\Support\Inventory\ItemNumbers;
use App\Support\Inventory\Placement;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create an item with a new number at its first place, or with the number of a stock label
 * (App\Models\ReservedNumber).
 */
class CreateItem
{
    public function __construct(private readonly ItemNumbers $numbers) {}

    /**
     * @param  array{name: string, description?: string|null, category_id?: int|null, kind: string, quantity?: int|null, unit?: string|null, is_container?: bool, public_contents?: bool, notes?: string|null}  $data
     * @param  list<UploadedFile>  $photos  photos of the gallery
     * @param  ReservedNumber|null  $reserved  number of a stock label, used up by this item
     */
    public function handle(User $actor, array $data, ItemCondition $condition, Location|Item|null $place, Location|Item|null $home, array $photos = [], ?ReservedNumber $reserved = null): Item
    {
        $item = new Item($data);
        Placement::check($item, $place, 'place');
        Placement::check($item, $home, 'home');

        return DB::transaction(function () use ($actor, $item, $condition, $place, $home, $photos, $reserved) {
            // single pieces always have the quantity 1
            if ($item->kind === ItemKind::Single) {
                $item->quantity = 1;
                $item->unit = null;
            }
            $item->condition()->associate($condition);
            $item->number = $reserved ? $this->useReserved($reserved) : $this->numbers->next();
            $item->setPlace($place);
            $item->setHome($home);
            $item->save();

            ItemMovement::record($item, MovementType::Create, null, $place, $actor);

            // throws if S3 can not store the photos; the transaction then also drops the item
            ItemPhoto::uploadAll($item, $photos);

            return $item;
        });
    }

    /**
     * Take the number of a reservation and mark it as used. The row stays locked until the
     * transaction ends, so a number is used only once.
     *
     * @throws ValidationException
     */
    private function useReserved(ReservedNumber $reserved): string
    {
        $reserved = ReservedNumber::open()->lockForUpdate()->find($reserved->id)
            ?? throw ValidationException::withMessages(['number' => "Die Nummer {$reserved->number} ist nicht mehr reserviert."]);
        $reserved->forceFill(['used_at' => now()])->save();

        return $reserved->number;
    }
}
