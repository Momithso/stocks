<?php

namespace Modules\Loans\Tests;

use App\Enums\ItemKind;
use App\Models\Item;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Models\Loan;
use Modules\Loans\Support\Availability;

/**
 * What came back with a partial return is free again while the rest of the loan is still out.
 */
class PartialReturnTest extends LoansTestCase
{
    private function returnPart(Loan $loan, string $code, array $data = []): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/check", ['code' => $code, ...$data])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/complete", ['returner' => 'Erika Muster', 'signatures' => ['returner' => self::SIGNATURE, 'receiver' => self::SIGNATURE]])->assertSessionHasNoErrors();
        $this->assertSame(LoanStatus::Issued, $loan->refresh()->status);
    }

    /**
     * Free for a new loan in the time the first one still runs, and no lock as "ausgegeben".
     */
    private function assertFree(int $itemId, int $quantity = 1): void
    {
        $item = Item::with('condition')->findOrFail($itemId);
        $check = app(Availability::class)->check($item, $quantity, now()->addHour(), now()->addHours(5));
        $this->assertFalse($check['blocked'], "{$item->number}: ".app(Availability::class)->message($item, $check));
        $notices = collect($this->actingAs($this->admin())->getJson("/inventory/lookup?code={$item->number}")->json('notices'));
        $this->assertFalse($notices->contains(fn ($notice) => str_contains($notice['text'], 'Ausgegeben an')), "{$item->number} gilt noch als ausgegeben.");
    }

    public function test_a_single_item_back_is_free_while_the_rest_is_out(): void
    {
        $back = $this->item();
        $loan = $this->issued([$back, $this->item()], now()->subHour(), now()->addDay());

        $this->returnPart($loan, $back->number);
        $this->assertFree($back->id);
    }

    public function test_a_bulk_part_back_counts_again_before_it_is_put_away(): void
    {
        $bulk = $this->item(['kind' => ItemKind::Bulk, 'quantity' => 10, 'unit' => 'Stück']);
        $loan = $this->issued([[$bulk, 4], $this->item()], now()->subHour(), now()->addDay());

        $part = $loan->positions()->where('item_id', $bulk->id)->first()->issuedItem;
        $this->returnPart($loan, $part->number, ['quantity' => 4]);
        // all ten again: six that stayed plus the four that came back
        $this->assertFree($bulk->id, 10);
    }

    public function test_a_row_of_a_box_back_is_free_while_the_box_is_out(): void
    {
        $box = $this->item(['is_container' => true]);
        $back = $this->item(['location_id' => null, 'container_id' => $box->id]);
        $this->item(['location_id' => null, 'container_id' => $box->id]);
        $loan = $this->issued([$box], now()->subHour(), now()->addDay());

        $this->returnPart($loan, $back->number);
        $this->assertFree($back->id);
    }

    public function test_the_issue_books_back_a_part_that_is_not_put_away_yet(): void
    {
        $bulk = $this->item(['kind' => ItemKind::Bulk, 'quantity' => 10, 'unit' => 'Stück']);
        $loan = $this->issued([[$bulk, 4], $this->item()], now()->subHour(), now()->addDay());
        $part = $loan->positions()->where('item_id', $bulk->id)->first()->issuedItem;
        $this->returnPart($loan, $part->number, ['quantity' => 4]);

        // all ten leave with the next loan: the four that came back go to the origin first
        $next = $this->issued([[$bulk, 10]]);
        $this->assertSame(LoanStatus::Issued, $next->status);
        $this->assertTrue($part->refresh()->isRetired(), 'the part went back to its origin');
        $this->assertNotNull($loan->positions()->where('item_id', $bulk->id)->first()->stowed_at);
    }
}
