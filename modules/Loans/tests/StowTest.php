<?php

namespace Modules\Loans\Tests;

use App\Models\Item;
use App\Models\Location;
use Modules\Loans\Actions\StowItem;

/**
 * Putting back after the return.
 */
class StowTest extends LoansTestCase
{
    private function returned(array $items)
    {
        $loan = $this->issued($items);
        $admin = $this->admin();
        foreach ($loan->loanItems as $row) {
            $this->actingAs($admin)->post("/loans/{$loan->id}/return/check", ['row_id' => $row->id]);
        }
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/complete", ['returner' => 'Erika', 'signatures' => ['returner' => self::SIGNATURE, 'receiver' => self::SIGNATURE]])->assertSessionHasNoErrors();

        return $loan->refresh();
    }

    public function test_the_scan_of_the_shelf_puts_the_item_back(): void
    {
        $item = $this->item();
        $loan = $this->returned([$item]);
        $row = $loan->positions()->first();

        $this->actingAs($this->admin())->get('/loans/returns')->assertInertia(fn ($page) => $page->has('pending', 1)->where('pending.0.home.code', 'R1'));
        $this->actingAs($this->admin())->post('/loans/returns/stow', ['row_id' => $row->id, 'code' => 'NIX'])->assertSessionHasErrors('code');
        $this->actingAs($this->admin())->post('/loans/returns/stow', ['row_id' => $row->id, 'code' => 'R1'])
            ->assertSessionHas('success', "{$item->number} liegt wieder an seinem Stammplatz.");

        $this->assertSame($this->shelf->id, $item->refresh()->location_id);
        $this->assertSame(0, StowItem::pending()->count());
    }

    public function test_another_place_than_the_home_is_said(): void
    {
        $item = $this->item();
        $row = $this->returned([$item])->positions()->first();
        Location::forceCreate(['type' => 'room', 'code' => 'R9', 'full_code' => 'R9']);

        $this->actingAs($this->admin())->post('/loans/returns/stow', ['row_id' => $row->id, 'code' => 'R9'])
            ->assertSessionHas('success', "{$item->number} liegt jetzt in R9, nicht am Stammplatz.");
    }

    public function test_split_parts_go_back_to_their_origin(): void
    {
        $benches = $this->item(['kind' => 'bulk', 'quantity' => 40, 'unit' => 'Stück']);
        $row = $this->returned([[$benches, 20]])->positions()->first();

        $this->actingAs($this->admin())->post('/loans/returns/stow', ['row_id' => $row->id])->assertSessionHasNoErrors();

        $this->assertSame(40, $benches->refresh()->quantity);
        $this->assertNotNull($row->issuedItem->refresh()->retired_at);
    }

    public function test_missing_pieces_that_turn_up_later_go_back_to_the_stock(): void
    {
        $cables = $this->item(['kind' => 'bulk', 'quantity' => 10, 'unit' => 'Stück']);
        $loan = $this->issued([[$cables, 6]]);
        $row = $loan->positions()->first();
        $admin = $this->admin();
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/check", ['row_id' => $row->id, 'quantity' => 4]);
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/complete", ['returner' => 'Erika', 'signatures' => ['returner' => self::SIGNATURE, 'receiver' => self::SIGNATURE]])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/loans/returns/stow', ['row_id' => $row->id])->assertSessionHasNoErrors();
        $this->assertSame(8, $cables->refresh()->quantity);

        // the 2 missing cables were split off the lent part, which is back in the stock by now
        $missing = Item::where('split_from_id', $row->issued_item_id)->firstOrFail();
        $this->actingAs($admin)->getJson("/inventory/lookup?code={$missing->number}")->assertJsonPath('can_merge', true);
        $this->actingAs($admin)->post("/inventory/items/{$missing->id}/merge", [])->assertSessionHasNoErrors();
        $this->assertSame(10, $cables->refresh()->quantity);
    }

    public function test_an_item_put_somewhere_else_by_hand_counts_as_put_back(): void
    {
        $item = $this->item();
        $this->returned([$item]);
        $this->actingAs($this->admin())->post("/inventory/items/{$item->id}/return-home")->assertSessionHasNoErrors();

        $this->assertSame(0, StowItem::pending()->count());
    }
}
