<?php

namespace Tests\Feature\Inventory;

use App\Models\Item;
use App\Models\ItemUnit;
use Tests\TestCase;

/**
 * The list of units, managed by admins.
 */
class UnitTest extends TestCase
{
    public function test_items_only_take_units_of_the_list(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.edit');
        $data = ['name' => 'Kabelbinder', 'kind' => 'bulk', 'quantity' => 100, 'condition_id' => $this->conditionId('neu')];

        $this->actingAs($actor)->post('/inventory/items', [...$data, 'unit' => 'Säcke'])->assertSessionHasErrors('unit');
        $this->actingAs($actor)->post('/inventory/items', [...$data, 'unit' => 'Packungen'])->assertSessionHasNoErrors();
    }

    public function test_renaming_a_unit_renames_it_at_the_items(): void
    {
        $item = Item::factory()->bulk(5, 'Rollen')->create();
        $unit = ItemUnit::firstWhere('name', 'Rollen');

        $this->actingAs($this->admin())->put("/inventory/units/{$unit->id}", ['name' => 'Rolle'])->assertSessionHasNoErrors();

        $this->assertSame('Rolle', $item->fresh()->unit);
    }

    public function test_a_unit_of_items_can_not_be_deleted(): void
    {
        Item::factory()->bulk(5, 'Meter')->create();
        $used = ItemUnit::firstWhere('name', 'Meter');
        $unused = ItemUnit::firstWhere('name', 'Sets');

        $this->actingAs($this->admin())->delete("/inventory/units/{$used->id}")->assertSessionHasErrors('unit');
        $this->actingAs($this->admin())->delete("/inventory/units/{$unused->id}")->assertSessionHasNoErrors();

        $this->assertModelExists($used);
        $this->assertModelMissing($unused);
    }

    public function test_consumables_can_be_marked_as_used_up_by_members(): void
    {
        $member = $this->userWithRoles('Freies Mitglied');
        $ties = Item::factory()->bulk(100, 'Packungen')->create();

        $this->actingAs($member)->get("/inventory/items/{$ties->id}")->assertInertia(fn ($page) => $page->where('consumable', true));
        $this->actingAs($member)->post("/inventory/items/{$ties->id}/consume", ['quantity' => 3, 'note' => 'Aufbau Party'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', "3 Packungen von {$ties->number} als verbraucht ausgebucht.");

        $this->assertSame(97, $ties->fresh()->quantity);
        $this->assertDatabaseHas('item_movements', ['item_id' => $ties->id, 'type' => 'consume', 'quantity' => 3, 'note' => 'Aufbau Party']);
    }

    public function test_only_consumables_can_be_marked_as_used_up(): void
    {
        $member = $this->userWithRoles('Freies Mitglied');
        $cables = Item::factory()->bulk(10, 'Stück')->create();

        $this->actingAs($member)->get("/inventory/items/{$cables->id}")->assertInertia(fn ($page) => $page->where('consumable', false));
        $this->actingAs($member)->post("/inventory/items/{$cables->id}/consume", ['quantity' => 1])->assertSessionHasErrors('quantity');
        $this->assertSame(10, $cables->fresh()->quantity);
    }

    public function test_only_admins_manage_units(): void
    {
        $this->actingAs($this->userWithRoles('Vorstand'))->get('/inventory/units')->assertForbidden();
        $this->actingAs($this->admin())->get('/inventory/units')->assertOk();
    }
}
