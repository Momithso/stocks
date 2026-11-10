<?php

namespace App\Actions\Inventory;

use App\Enums\ItemKind;
use App\Enums\MovementType;
use App\Models\Item;
use App\Models\ItemCondition;
use App\Models\ItemMovement;
use App\Models\ItemPhoto;
use App\Models\Location;
use App\Models\User;
use App\Support\Inventory\ItemNumbers;
use App\Support\Inventory\Placement;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Create an item with a new number at its first place.
 */
class CreateItem
{
    public function __construct(private readonly ItemNumbers $numbers) {}

    /**
     * @param  array{name: string, description?: string|null, category_id?: int|null, kind: string, quantity?: int|null, unit?: string|null, is_container?: bool, public_contents?: bool, notes?: string|null}  $data
     * @param  list<UploadedFile>  $photos  photos of the gallery
     */
    public function handle(User $actor, array $data, ItemCondition $condition, Location|Item|null $place, Location|Item|null $home, array $photos = []): Item
    {
        $item = new Item($data);
        Placement::check($item, $place, 'place');
        Placement::check($item, $home, 'home');

        return DB::transaction(function () use ($actor, $item, $condition, $place, $home, $photos) {
            // single pieces always have the quantity 1
            if ($item->kind === ItemKind::Single) {
                $item->quantity = 1;
                $item->unit = null;
            }
            $item->condition()->associate($condition);
            $item->number = $this->numbers->next();
            $item->setPlace($place);
            $item->setHome($home);
            $item->save();

            ItemMovement::record($item, MovementType::Create, null, $place, $actor);

            // throws if S3 can not store the photos; the transaction then also drops the item
            ItemPhoto::uploadAll($item, $photos);

            return $item;
        });
    }
}
