<?php

namespace Database\Seeders;

use App\Actions\Inventory\CreateItem;
use App\Actions\Inventory\SaveLocation;
use App\Models\Category;
use App\Models\ItemCondition;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo inventory: one address with a room, a shelf, two compartments, a box and some items.
 * Created through the actions, so numbers and movement log are real.
 */
class InventorySeeder extends Seeder
{
    /**
     * Seed the inventory.
     */
    public function run(SaveLocation $saveLocation, CreateItem $createItem): void
    {
        $actor = User::where('email', 'admin@example.com')->firstOrFail();

        $address = $saveLocation->handle(null, ['parent_id' => null, 'code' => 'EU', 'name' => 'Eupener Straße']);
        $room = $saveLocation->handle(null, ['parent_id' => $address->id, 'code' => '014', 'name' => 'Lager']);
        $shelf = $saveLocation->handle(null, ['parent_id' => $room->id, 'code' => 'R03', 'name' => 'Schwerlastregal']);
        $bottom = $saveLocation->handle(null, ['parent_id' => $shelf->id, 'code' => '1']);
        $top = $saveLocation->handle(null, ['parent_id' => $shelf->id, 'code' => '4']);

        $condition = fn (string $name) => ItemCondition::where('name', $name)->firstOrFail();

        $technik = Category::create(['name' => 'Technik']);
        $kabel = Category::create(['name' => 'Kabel', 'parent_id' => $technik->id]);
        $party = Category::create(['name' => 'Party']);

        $box = $createItem->handle($actor, ['name' => 'Kabelkiste', 'kind' => 'single', 'is_container' => true, 'category_id' => $kabel->id], $condition('gut'), $bottom, $bottom);
        $createItem->handle($actor, ['name' => 'XLR-Kabel 5 m', 'kind' => 'bulk', 'quantity' => 12, 'unit' => 'Stück', 'category_id' => $kabel->id], $condition('gebraucht'), $box, $box);
        $createItem->handle($actor, ['name' => 'Kaltgerätekabel', 'kind' => 'bulk', 'quantity' => 8, 'unit' => 'Stück', 'category_id' => $kabel->id], $condition('gut'), $box, null);
        $createItem->handle($actor, ['name' => 'Beamer Epson', 'kind' => 'single', 'category_id' => $technik->id], $condition('gut'), $room, $top);
        $createItem->handle($actor, ['name' => 'Bierzeltgarnitur', 'kind' => 'bulk', 'quantity' => 6, 'unit' => 'Sets', 'category_id' => $party->id], $condition('gebraucht'), $room, null);
        $createItem->handle($actor, ['name' => 'Gaffa-Tape', 'kind' => 'bulk', 'quantity' => 12, 'unit' => 'Rollen', 'category_id' => $technik->id], $condition('neu'), $bottom, null);
        $createItem->handle($actor, ['name' => 'Lichterkette', 'kind' => 'single', 'category_id' => $party->id], $condition('defekt'), null, null);
    }
}
