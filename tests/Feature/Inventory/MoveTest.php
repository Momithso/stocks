<?php

namespace Tests\Feature\Inventory;

use App\Models\Item;
use App\Models\Location;
use Tests\TestCase;

/**
 * Moving: movement log, boxes, splitting bulk items, home.
 */
class MoveTest extends TestCase
{
    public function test_moving_changes_the_place_and_writes_the_log(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.move');
        $from = Location::factory()->create(['code' => 'EU']);
        $to = Location::factory()->create(['code' => 'GO']);
        $item = Item::factory()->at($from)->create();

        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $item->id, 'target_code' => 'go', 'note' => 'Für die Party'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', "{$item->number} liegt jetzt in GO.");

        $this->assertTrue($item->fresh()->location->is($to));
        $this->assertDatabaseHas('item_movements', [
            'item_id' => $item->id, 'type' => 'move', 'from_location_id' => $from->id, 'to_location_id' => $to->id, 'user_id' => $actor->id, 'note' => 'Für die Party',
        ]);
    }

    public function test_moving_does_not_change_the_home(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.move');
        $home = Location::factory()->create(['code' => 'EU']);
        Location::factory()->create(['code' => 'GO']);
        $item = Item::factory()->at($home)->create(['home_location_id' => $home->id]);

        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $item->id, 'target_code' => 'GO']);

        $item->refresh();
        $this->assertTrue($item->homeLocation->is($home));
        $this->assertTrue($item->isAwayFromHome());

        $this->actingAs($actor)->post("/inventory/items/{$item->id}/return-home")->assertSessionHasNoErrors();
        $this->assertFalse($item->fresh()->isAwayFromHome());
    }

    public function test_the_contents_move_with_their_box(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.move');
        $from = Location::factory()->create(['code' => 'EU']);
        $to = Location::factory()->create(['code' => 'GO']);
        $box = Item::factory()->container()->at($from)->create();
        $innerBox = Item::factory()->container()->at($box)->create();
        $cable = Item::factory()->at($innerBox)->create();

        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $box->id, 'target_code' => 'GO']);

        $this->assertTrue($cable->fresh()->effectiveLocation()->is($to));
        // only the box itself gets a row in the log
        $this->assertDatabaseMissing('item_movements', ['item_id' => $cable->id]);
    }

    public function test_items_can_be_put_into_a_box_by_its_number(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.move');
        $box = Item::factory()->container()->create(['number' => 'FSR5-000117']);
        $item = Item::factory()->create();

        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $item->id, 'target_code' => 'fsr5000117'])->assertSessionHasNoErrors();

        $this->assertTrue($item->fresh()->container->is($box));
    }

    public function test_a_box_can_not_go_into_itself_or_its_own_contents(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.move');
        $box = Item::factory()->container()->create(['number' => 'TEST-1']);
        $innerBox = Item::factory()->container()->at($box)->create(['number' => 'TEST-2']);

        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $box->id, 'target_code' => 'TEST-1'])->assertSessionHasErrors('target');
        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $box->id, 'target_code' => 'TEST-2'])->assertSessionHasErrors('target');
        $this->assertNull($box->fresh()->container_id);
        $this->assertTrue($innerBox->fresh()->container->is($box));
    }

    public function test_items_do_not_lie_directly_at_an_address(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.move', 'inventory.edit');
        $address = Location::factory()->address()->create(['code' => 'EU']);
        Location::factory()->in($address)->create(['code' => '014']);
        $item = Item::factory()->create();

        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $item->id, 'target_code' => 'EU'])->assertSessionHasErrors('target');
        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $item->id, 'target_code' => 'EU-014'])->assertSessionHasNoErrors();
        $this->actingAs($actor)->post('/inventory/items', ['name' => 'Beamer', 'kind' => 'single', 'condition_id' => $this->conditionId('gut'), 'place_code' => 'EU'])->assertSessionHasErrors('place');
        $this->actingAs($actor)->put("/inventory/items/{$item->id}", ['name' => $item->name, 'kind' => 'single', 'home_code' => 'EU'])->assertSessionHasErrors('home');
    }

    public function test_items_only_go_into_boxes(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.move');
        Item::factory()->create(['number' => 'TEST-1']);
        $item = Item::factory()->create();

        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $item->id, 'target_code' => 'TEST-1'])->assertSessionHasErrors('target_code');
    }

    public function test_moving_a_part_of_a_bulk_item_splits_it(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.move');
        $from = Location::factory()->create(['code' => 'EU']);
        $to = Location::factory()->create(['code' => 'GO']);
        $cables = Item::factory()->bulk(20)->at($from)->create(['name' => 'XLR-Kabel']);

        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $cables->id, 'target_code' => 'GO', 'quantity' => 5])->assertSessionHasNoErrors();

        $part = Item::where('split_from_id', $cables->id)->sole();
        $this->assertSame(15, $cables->fresh()->quantity);
        $this->assertSame(5, $part->quantity);
        $this->assertSame('XLR-Kabel', $part->name);
        $this->assertNotSame($cables->number, $part->number);
        $this->assertTrue($part->location->is($to));
        $this->assertTrue($cables->fresh()->location->is($from));
        $this->assertDatabaseHas('item_movements', ['item_id' => $cables->id, 'type' => 'split', 'quantity' => 5, 'to_location_id' => $to->id]);
        $this->assertDatabaseHas('item_movements', ['item_id' => $part->id, 'type' => 'split', 'quantity' => 5, 'from_location_id' => $from->id]);
    }

    public function test_the_quantity_can_not_exceed_the_stock(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.move');
        Location::factory()->create(['code' => 'GO']);
        $cables = Item::factory()->bulk(3)->create();

        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $cables->id, 'target_code' => 'GO', 'quantity' => 4])->assertSessionHasErrors('quantity');
    }

    public function test_the_misplaced_list_shows_items_without_place_or_away_from_home(): void
    {
        $actor = $this->userWithPermissions('inventory.view');
        $home = Location::factory()->create();
        $other = Location::factory()->create();
        Item::factory()->create(['name' => 'Ohne Ort']);
        Item::factory()->at($other)->create(['name' => 'Woanders', 'home_location_id' => $home->id]);
        Item::factory()->at($home)->create(['name' => 'Daheim', 'home_location_id' => $home->id]);
        Item::factory()->at($other)->create(['name' => 'Ohne Stammplatz']);

        $this->actingAs($actor)->get('/inventory/misplaced?sort=name')
            ->assertInertia(fn ($page) => $page->has('items.data', 2)->where('items.data.0.name', 'Ohne Ort')->where('items.data.1.name', 'Woanders'));
    }
}
