<?php

namespace Tests\Feature\Inventory;

use App\Models\Item;
use App\Models\Location;
use Tests\TestCase;

/**
 * Booking a split off part of a bulk item back to its origin.
 */
class MergeTest extends TestCase
{
    /**
     * Split 5 of 12 rolls into the room GO.
     *
     * @return array{0: Item, 1: Item}
     */
    private function split(): array
    {
        $this->actingAs($this->admin());
        $store = Location::factory()->create(['code' => 'EU']);
        Location::factory()->create(['code' => 'GO']);
        $tape = Item::factory()->bulk(12, 'Rollen')->at($store)->create();
        $this->post('/inventory/move', ['item_id' => $tape->id, 'target_code' => 'GO', 'quantity' => 5]);

        return [$tape, Item::where('split_from_id', $tape->id)->sole()];
    }

    public function test_a_part_is_booked_back_completely(): void
    {
        [$tape, $part] = $this->split();

        $this->post("/inventory/items/{$part->id}/merge")->assertRedirect("/inventory/items/{$tape->id}");

        $this->assertSame(12, $tape->fresh()->quantity);
        $part->refresh();
        $this->assertTrue($part->isRetired());
        $this->assertSame('merged', $part->retired_reason->value);
        $this->assertDatabaseHas('item_movements', ['item_id' => $tape->id, 'type' => 'merge', 'quantity' => 5]);
        $this->assertDatabaseHas('item_movements', ['item_id' => $part->id, 'type' => 'merge', 'quantity' => 5]);
    }

    public function test_a_part_is_booked_back_partly(): void
    {
        [$tape, $part] = $this->split();

        $this->post("/inventory/items/{$part->id}/merge", ['quantity' => 2])->assertSessionHasNoErrors();

        $this->assertSame(9, $tape->fresh()->quantity);
        $this->assertSame(3, $part->fresh()->quantity);
        $this->assertFalse($part->fresh()->isRetired());
    }

    public function test_scanning_the_origin_books_the_part_back(): void
    {
        [$tape, $part] = $this->split();

        $this->post('/inventory/move', ['item_id' => $part->id, 'target_code' => $tape->number])->assertSessionHasNoErrors();

        $this->assertSame(12, $tape->fresh()->quantity);
        $this->assertTrue($part->fresh()->isRetired());
    }

    public function test_only_split_parts_can_be_booked_back(): void
    {
        [$tape] = $this->split();

        $this->post("/inventory/items/{$tape->id}/merge")->assertSessionHasErrors('item');
        $this->post("/inventory/items/{$tape->id}/merge", ['quantity' => 99])->assertSessionHasErrors('item');
    }

    public function test_more_than_the_part_can_not_be_booked_back(): void
    {
        [, $part] = $this->split();

        $this->post("/inventory/items/{$part->id}/merge", ['quantity' => 6])->assertSessionHasErrors('quantity');
    }
}
