<?php

namespace Tests\Feature\Inventory;

use App\Models\Item;
use App\Models\Location;
use Tests\TestCase;

/**
 * Locations: levels, full codes, deleting.
 */
class LocationTest extends TestCase
{
    public function test_a_tree_of_locations_gets_full_codes(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'locations.manage');

        $this->actingAs($actor)->post('/inventory/locations', ['code' => 'eu', 'name' => 'Eupener Straße'])->assertSessionHasNoErrors();
        $address = Location::firstWhere('code', 'EU');
        $this->actingAs($actor)->post('/inventory/locations', ['parent_id' => $address->id, 'code' => '014']);
        $room = Location::firstWhere('full_code', 'EU-014');
        $this->actingAs($actor)->post('/inventory/locations', ['parent_id' => $room->id, 'code' => 'R03']);
        $shelf = Location::firstWhere('full_code', 'EU-014-R03');
        $this->actingAs($actor)->post('/inventory/locations', ['parent_id' => $shelf->id, 'code' => '4']);

        $this->assertDatabaseHas('locations', ['full_code' => 'EU-014-R03.4', 'type' => 'compartment']);
    }

    public function test_renaming_and_moving_recomputes_the_codes_below(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'locations.manage');
        $address = Location::factory()->address()->create(['code' => 'EU']);
        $otherAddress = Location::factory()->address()->create(['code' => 'GO']);
        $room = Location::factory()->in($address)->create(['code' => '014']);
        $shelf = Location::factory()->in($room)->create(['code' => 'R03']);
        $compartment = Location::factory()->in($shelf)->create(['code' => '4']);

        $this->actingAs($actor)->put("/inventory/locations/{$room->id}", ['parent_id' => $address->id, 'code' => '015'])->assertSessionHasNoErrors();
        $this->assertSame('EU-015-R03.4', $compartment->fresh()->full_code);

        $this->actingAs($actor)->put("/inventory/locations/{$room->id}", ['parent_id' => $otherAddress->id, 'code' => '015'])->assertSessionHasNoErrors();
        $this->assertSame('GO-015-R03.4', $compartment->fresh()->full_code);
    }

    public function test_codes_are_unique_below_the_same_parent(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'locations.manage');
        $address = Location::factory()->address()->create(['code' => 'EU']);
        Location::factory()->in($address)->create(['code' => '014']);

        $this->actingAs($actor)->post('/inventory/locations', ['parent_id' => $address->id, 'code' => '014'])->assertSessionHasErrors('code');
    }

    public function test_a_location_must_lie_in_the_level_above(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'locations.manage');
        $address = Location::factory()->address()->create(['code' => 'EU']);
        $room = Location::factory()->in($address)->create(['code' => '014']);
        $otherAddress = Location::factory()->address()->create(['code' => 'GO']);

        // a room can not move into a room
        $this->actingAs($actor)->put("/inventory/locations/{$room->id}", ['parent_id' => $room->id, 'code' => '014'])->assertSessionHasErrors('parent_id');
        // an address can not lie in an address
        $this->actingAs($actor)->put("/inventory/locations/{$otherAddress->id}", ['parent_id' => $address->id, 'code' => 'GO'])->assertSessionHasErrors('parent_id');
    }

    public function test_a_location_is_only_deleted_when_nothing_lies_below_it(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'locations.manage');
        $address = Location::factory()->address()->create(['code' => 'EU']);
        $room = Location::factory()->in($address)->create(['code' => '014']);
        $item = Item::factory()->at($room)->create();

        $this->actingAs($actor)->delete("/inventory/locations/{$address->id}")->assertSessionHasErrors('location');

        $item->delete();
        $this->actingAs($actor)->delete("/inventory/locations/{$address->id}")->assertRedirect('/inventory/locations');
        $this->assertDatabaseCount('locations', 0);
    }

    public function test_locations_need_the_permission(): void
    {
        $this->actingAs($this->userWithPermissions('inventory.view'))->post('/inventory/locations', ['code' => 'EU'])->assertForbidden();
        $this->actingAs($this->userWithPermissions())->get('/inventory/locations')->assertForbidden();
    }
}
