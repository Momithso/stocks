<?php

namespace Modules\Loans\Tests;

use App\Support\Settings;
use Modules\Loans\Support\Availability;

/**
 * Availability: overlap, boxes and their contents, quantities, buffer, next free time, drafts.
 */
class AvailabilityTest extends LoansTestCase
{
    private function check($item, int $quantity, int $fromDays, int $toDays): array
    {
        return app(Availability::class)->check($item->refresh()->load('condition'), $quantity, now()->addDays($fromDays), now()->addDays($toDays));
    }

    public function test_an_overlapping_confirmed_loan_blocks_and_names_the_next_free_time(): void
    {
        $item = $this->item();
        $other = $this->confirmed([$item], now()->addDays(2), now()->addDays(4));

        $check = $this->check($item, 1, 3, 5);
        $this->assertTrue($check['blocked']);
        $this->assertSame($other->number, $check['conflicts'][0]['number']);
        $this->assertSame($other->return_at->toIso8601String(), $check['next_free']);

        $this->assertFalse($this->check($item, 1, 5, 6)['blocked']);
        $this->assertFalse($this->check($item, 1, 0, 1)['blocked']);
    }

    public function test_drafts_do_not_block_but_are_named(): void
    {
        $item = $this->item();
        $draft = $this->draft([$item], now()->addDays(2), now()->addDays(4));

        $check = $this->check($item, 1, 3, 5);
        $this->assertFalse($check['blocked']);
        $this->assertSame($draft->number, $check['drafts'][0]['number']);

        // whoever confirms first gets the item; the other draft shows the collision
        $second = $this->draft([$item], now()->addDays(3), now()->addDays(5));
        $this->actingAs($this->admin())->post("/loans/{$draft->id}/confirm")->assertSessionHasNoErrors();
        $this->actingAs($this->admin())->post("/loans/{$second->id}/confirm")->assertSessionHasErrors('loan');
    }

    public function test_a_booked_box_takes_its_contents_and_a_booked_content_makes_the_box_incomplete(): void
    {
        $box = $this->item(['is_container' => true]);
        $inside = $this->item(['location_id' => null, 'container_id' => $box->id]);
        $this->confirmed([$box], now()->addDays(2), now()->addDays(4));

        $this->assertTrue($this->check($inside, 1, 3, 5)['blocked']);

        $otherBox = $this->item(['is_container' => true]);
        $content = $this->item(['location_id' => null, 'container_id' => $otherBox->id]);
        $this->confirmed([$content], now()->addDays(2), now()->addDays(4));

        $check = $this->check($otherBox, 1, 3, 5);
        $this->assertFalse($check['blocked']);
        $this->assertStringStartsWith('Unvollständig', $check['hints'][0]);
    }

    public function test_bulk_items_count_their_quantity(): void
    {
        $benches = $this->item(['kind' => 'bulk', 'quantity' => 40, 'unit' => 'Stück']);
        $this->confirmed([[$benches, 25]], now()->addDays(2), now()->addDays(4));

        $check = $this->check($benches, 20, 3, 5);
        $this->assertTrue($check['blocked']);
        $this->assertSame(15, $check['free']);
        $this->assertFalse($this->check($benches, 15, 3, 5)['blocked']);
    }

    public function test_an_issued_part_of_a_bulk_item_still_counts_to_the_stock(): void
    {
        $benches = $this->item(['kind' => 'bulk', 'quantity' => 40, 'unit' => 'Stück']);
        $this->issued([[$benches, 25]], now()->subHour(), now()->addDay());

        $this->assertSame(15, $benches->refresh()->quantity);
        // during the loan 15 are free, afterwards all 40
        $this->assertSame(15, $this->check($benches, 1, 0, 1)['free']);
        $this->assertSame(40, $this->check($benches, 1, 3, 4)['free']);
    }

    public function test_the_buffer_keeps_hours_free_between_loans(): void
    {
        $item = $this->item();
        $this->confirmed([$item], now()->addDays(2), now()->addDays(3));

        $this->assertFalse(app(Availability::class)->check($item->refresh()->load('condition'), 1, now()->addDays(3)->addHours(2), now()->addDays(4))['blocked']);
        app(Settings::class)->set(['loans.buffer_hours' => 4]);
        $this->assertTrue(app(Availability::class)->check($item->refresh()->load('condition'), 1, now()->addDays(3)->addHours(2), now()->addDays(4))['blocked']);
    }

    public function test_an_overdue_loan_holds_its_items_until_they_are_back(): void
    {
        $item = $this->item();
        $this->issued([$item], now()->subDays(3), now()->subDay());

        $check = $this->check($item, 1, 10, 11);
        $this->assertTrue($check['blocked']);
        $this->assertTrue($check['conflicts'][0]['overdue']);
        $this->assertNull($check['next_free']);
    }

    public function test_a_confirmed_loan_only_takes_free_items(): void
    {
        $item = $this->item();
        $this->confirmed([$item], now()->addDays(2), now()->addDays(4));
        $loan = $this->confirmed([$this->item()], now()->addDays(3), now()->addDays(5));

        $this->actingAs($this->admin())->post("/loans/{$loan->id}/positions", ['item_id' => $item->id])->assertSessionHasErrors('item');
        $this->actingAs($this->admin())->put("/loans/{$loan->id}", [
            'kind' => 'external', 'responsible_contact_id' => $loan->responsible_contact_id,
            'pickup_at' => now()->addDays(10)->toDateTimeString(), 'return_at' => now()->addDays(11)->toDateTimeString(),
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->admin())->post("/loans/{$loan->id}/positions", ['item_id' => $item->id])->assertSessionHasNoErrors();
    }

    public function test_an_item_in_a_booked_box_shows_the_booking(): void
    {
        $outer = $this->item(['is_container' => true]);
        $box = $this->item(['is_container' => true, 'location_id' => null, 'container_id' => $outer->id]);
        $inside = $this->item(['location_id' => null, 'container_id' => $box->id]);
        $loan = $this->confirmed([$outer]);
        $admin = $this->admin();

        $this->actingAs($admin)->getJson("/inventory/lookup?code={$inside->number}")
            ->assertJsonPath('notices.0.text', "Gebucht mit der Box {$outer->number} in {$loan->number}: {$loan->pickup_at->format('d.m.')} bis {$loan->return_at->format('d.m.Y')}.");
        $this->actingAs($admin)->getJson("/loans/items/{$inside->id}")->assertJsonPath('loans.0.id', $loan->id);
        $this->actingAs($admin)->getJson("/loans/items/{$this->item()->id}")->assertJsonCount(0, 'loans');
    }
}
