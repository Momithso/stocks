<?php

namespace Tests\Feature\Inventory;

use App\Enums\MovementType;
use App\Models\Item;
use App\Models\Location;
use App\Support\Inventory\ItemNumbers;
use App\Support\Settings;
use Tests\TestCase;

/**
 * Items: numbers, creating, changing, retiring, deleting.
 */
class ItemTest extends TestCase
{
    public function test_an_item_gets_a_number_with_prefix_and_check_digit(): void
    {
        app(Settings::class)->set(['inventory.number_prefix' => 'FSR5']);
        $actor = $this->userWithPermissions('inventory.view', 'inventory.edit');
        $room = Location::factory()->create(['code' => 'EU']);

        $this->actingAs($actor)->post('/inventory/items', [
            'name' => 'Beamer', 'kind' => 'single', 'condition_id' => $this->conditionId('gut'), 'place_code' => 'eu',
        ])->assertSessionHasNoErrors();
        $this->actingAs($actor)->post('/inventory/items', ['name' => 'Kabel', 'kind' => 'bulk', 'quantity' => 20, 'unit' => 'Stück', 'condition_id' => $this->conditionId('neu')]);

        $beamer = Item::firstWhere('name', 'Beamer');
        $this->assertSame('FSR5-000018', $beamer->number);
        $this->assertSame('FSR5-000026', Item::firstWhere('name', 'Kabel')->number);
        $this->assertTrue($beamer->location->is($room));
        $this->assertSame(MovementType::Create, $beamer->movements()->first()->type);
    }

    public function test_the_check_digit_follows_luhn(): void
    {
        $this->assertSame(8, ItemNumbers::checkDigit('00421'));
        $this->assertTrue(ItemNumbers::hasWrongCheckDigit('FSR5-004217'));
        $this->assertFalse(ItemNumbers::hasWrongCheckDigit('FSR5-004218'));
    }

    public function test_the_prefix_defaults_to_the_short_name(): void
    {
        app(Settings::class)->set(['organization.short_name' => 'FSR 5']);

        $this->assertSame('FSR5', app(ItemNumbers::class)->prefix());
    }

    public function test_codes_are_found_with_and_without_hyphen(): void
    {
        $actor = $this->userWithPermissions('inventory.view');
        $item = Item::factory()->create(['number' => 'FSR5-004218']);

        $this->actingAs($actor)->getJson('/inventory/lookup?code=fsr5004218')->assertOk()->assertJson(['type' => 'item', 'id' => $item->id]);
        $this->actingAs($actor)->getJson('/inventory/lookup?code=FSR5-004218')->assertJson(['id' => $item->id]);
        $this->actingAs($actor)->getJson('/inventory/lookup?code=FSR5-004217')->assertNotFound()->assertJsonFragment(['message' => 'Die Prüfziffer von FSR5-004217 stimmt nicht. Ist eine Ziffer vertippt?']);
    }

    public function test_place_fields_suggest_locations_and_boxes(): void
    {
        $actor = $this->userWithPermissions('inventory.view');
        $address = Location::factory()->address()->create(['code' => 'EU']);
        Location::factory()->in($address)->create(['code' => 'R03', 'name' => 'Lager']);
        Item::factory()->container()->create(['name' => 'R03-Kiste']);
        Item::factory()->create(['name' => 'R03-Kabel']);

        $this->actingAs($actor)->getJson('/inventory/places?search=R03')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.code', 'EU-R03')
            ->assertJsonPath('1.name', 'R03-Kiste');
    }

    public function test_changing_the_quantity_is_logged(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.edit');
        $item = Item::factory()->bulk(10)->create();

        $this->actingAs($actor)->put("/inventory/items/{$item->id}", ['name' => $item->name, 'kind' => 'bulk', 'quantity' => 7, 'unit' => 'Stück'])->assertSessionHasNoErrors();

        $this->assertSame(7, $item->fresh()->quantity);
        $this->assertDatabaseHas('item_movements', ['item_id' => $item->id, 'type' => 'count', 'note' => 'Menge 10 → 7']);
    }

    public function test_a_box_with_contents_stays_a_box(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.edit');
        $box = Item::factory()->container()->create();
        Item::factory()->at($box)->create();

        $this->actingAs($actor)->put("/inventory/items/{$box->id}", ['name' => $box->name, 'kind' => 'single', 'is_container' => false])->assertSessionHasErrors('is_container');
    }

    public function test_the_condition_only_changes_with_a_reason(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.move');
        $item = Item::factory()->create();

        $this->actingAs($actor)->post("/inventory/items/{$item->id}/condition", ['condition_id' => $this->conditionId('defekt')])->assertSessionHasErrors('reason');
        $this->actingAs($actor)->post("/inventory/items/{$item->id}/condition", ['condition_id' => $this->conditionId('defekt'), 'reason' => 'Lampe kaputt'])->assertSessionHasNoErrors();

        $this->assertSame('defekt', $item->fresh()->condition->name);
        $this->assertDatabaseHas('item_condition_changes', ['item_id' => $item->id, 'from_condition' => 'gut', 'to_condition' => 'defekt', 'reason' => 'Lampe kaputt', 'user_id' => $actor->id]);
    }

    public function test_the_condition_is_not_changed_by_editing(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.edit');
        $item = Item::factory()->create();

        $this->actingAs($actor)->put("/inventory/items/{$item->id}", ['name' => 'Neu', 'kind' => 'single', 'condition_id' => $this->conditionId('defekt')]);

        $this->assertSame('gut', $item->fresh()->condition->name);
    }

    public function test_a_retired_item_has_no_place_and_keeps_its_history(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.retire');
        $room = Location::factory()->create();
        $item = Item::factory()->at($room)->create();

        $this->actingAs($actor)->post("/inventory/items/{$item->id}/retire", ['reason' => 'lost', 'note' => 'Nach der Party weg'])->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertTrue($item->isRetired());
        $this->assertNull($item->place());
        $this->assertDatabaseHas('item_movements', ['item_id' => $item->id, 'type' => 'retire', 'from_location_id' => $room->id]);
    }

    public function test_a_part_of_a_bulk_item_can_be_retired_as_consumed(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.retire');
        $room = Location::factory()->create();
        $ties = Item::factory()->bulk(100)->at($room)->create();

        $this->actingAs($actor)->post("/inventory/items/{$ties->id}/retire", ['reason' => 'consumed', 'quantity' => 30, 'note' => 'Party'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', "30 Stück von {$ties->number} als verbraucht ausgebucht.");

        $ties->refresh();
        $this->assertSame(70, $ties->quantity);
        $this->assertFalse($ties->isRetired());
        $this->assertTrue($ties->location->is($room));
        $this->assertDatabaseHas('item_movements', ['item_id' => $ties->id, 'type' => 'consume', 'quantity' => 30, 'from_location_id' => $room->id, 'note' => 'Party']);
    }

    public function test_retiring_the_whole_quantity_retires_the_item(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.retire');
        $ties = Item::factory()->bulk(10)->create();

        $this->actingAs($actor)->post("/inventory/items/{$ties->id}/retire", ['reason' => 'consumed', 'quantity' => 10])->assertSessionHasNoErrors();
        $this->assertTrue($ties->fresh()->isRetired());

        $other = Item::factory()->bulk(10)->create();
        $this->actingAs($actor)->post("/inventory/items/{$other->id}/retire", ['reason' => 'consumed', 'quantity' => 11])->assertSessionHasErrors('quantity');
    }

    public function test_a_box_is_only_retired_when_it_is_empty(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.retire');
        $box = Item::factory()->container()->create();
        Item::factory()->at($box)->create();

        $this->actingAs($actor)->post("/inventory/items/{$box->id}/retire", ['reason' => 'defective'])->assertSessionHasErrors('item');
    }

    public function test_only_items_without_history_can_be_deleted(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.edit', 'inventory.move', 'inventory.retire');
        $room = Location::factory()->create(['code' => 'EU']);
        $otherRoom = Location::factory()->create(['code' => 'GO']);
        $this->actingAs($actor)->post('/inventory/items', ['name' => 'Fehler', 'kind' => 'single', 'condition_id' => $this->conditionId('gut'), 'place_code' => 'EU']);
        $this->actingAs($actor)->post('/inventory/items', ['name' => 'Beamer', 'kind' => 'single', 'condition_id' => $this->conditionId('gut'), 'place_code' => 'EU']);
        $mistake = Item::firstWhere('name', 'Fehler');
        $moved = Item::firstWhere('name', 'Beamer');
        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $moved->id, 'target_code' => 'GO']);

        $this->actingAs($actor)->delete("/inventory/items/{$moved->id}")->assertSessionHasErrors('item');
        $this->actingAs($actor)->delete("/inventory/items/{$mistake->id}")->assertRedirect('/inventory/items');
        $this->assertModelMissing($mistake);
    }

    public function test_free_members_may_look_and_move_but_not_create(): void
    {
        $member = $this->userWithRoles('Freies Mitglied');
        $item = Item::factory()->create();

        $this->actingAs($member)->get('/inventory/items')->assertOk();
        $this->actingAs($member)->get("/inventory/items/{$item->id}")->assertOk();
        $this->actingAs($member)->get('/inventory/move')->assertOk();
        $this->actingAs($member)->get('/inventory/items/create')->assertForbidden();
        $this->actingAs($member)->post("/inventory/items/{$item->id}/retire", ['reason' => 'lost'])->assertForbidden();
    }

    public function test_the_list_finds_items_by_number_name_and_location_code(): void
    {
        $actor = $this->userWithPermissions('inventory.view');
        $room = Location::factory()->create(['code' => 'EU']);
        Item::factory()->at($room)->create(['name' => 'Xylophon', 'number' => 'FSR5-000018']);
        Item::factory()->create(['name' => 'Zzzuletzt']);

        foreach (['Xylo', '000018', 'EU'] as $search) {
            $this->actingAs($actor)->get('/inventory/items?search='.$search)
                ->assertInertia(fn ($page) => $page->component('Inventory/Items/Index')->has('items.data', 1)->where('items.data.0.name', 'Xylophon'));
        }
    }
}
