<?php

namespace Modules\Loans\Tests;

use App\Enums\RetireReason;
use App\Models\Item;
use Illuminate\Support\Carbon;
use Modules\Loans\Models\Loan;
use Modules\Loans\Models\LoanItem;

/**
 * What went missing at a return: "vermisst", turns up again ("Wieder da") or is written off as lost
 * when the defects are closed.
 */
class MissingTest extends LoansTestCase
{
    /**
     * A loan returned with the first row missing (a bulk row: the given number came back).
     */
    private function returnedWithMissing(Item $item, ?int $quantity = null, ?int $back = null): Loan
    {
        $loan = $this->issued([$quantity ? [$item, $quantity] : $item]);
        $row = $loan->positions()->firstOrFail();
        $admin = $this->admin();
        $back === null
            ? $this->actingAs($admin)->post("/loans/{$loan->id}/return/rows/{$row->id}/defect", ['state' => 'missing', 'note' => 'nicht auffindbar'])
            : $this->actingAs($admin)->post("/loans/{$loan->id}/return/check", ['row_id' => $row->id, 'quantity' => $back]);
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/complete", ['returner' => 'Erika', 'signatures' => ['returner' => self::SIGNATURE, 'receiver' => self::SIGNATURE]])->assertSessionHasNoErrors();

        return $loan->refresh();
    }

    public function test_a_missing_item_is_marked_and_can_not_be_booked(): void
    {
        $item = $this->item();
        $loan = $this->returnedWithMissing($item);
        $admin = $this->admin();

        $this->assertSame($item->id, $loan->positions()->first()->missing_item_id);
        $this->actingAs($admin)->getJson("/inventory/lookup?code={$item->number}")
            ->assertJsonPath('notices.0.badge', 'vermisst')
            ->assertJsonPath('notices.0.actions.0.href', "/loans/{$loan->id}#vermisst");
        $this->actingAs($admin)->get("/loans/{$loan->id}")->assertInertia(fn ($page) => $page->has('missing', 1)->where('missing.0.number', $item->number));

        // a new loan does not take it
        $next = $this->draft([], Carbon::now()->addDays(3), Carbon::now()->addDays(4));
        $this->actingAs($admin)->post("/loans/{$next->id}/positions", ['item_id' => $item->id])->assertSessionHasErrors();
        $this->assertSame(0, $next->positions()->count());
    }

    public function test_a_found_item_waits_to_be_put_back_and_the_defects_close(): void
    {
        $item = $this->item();
        $loan = $this->returnedWithMissing($item);
        $row = $loan->positions()->first();

        $this->actingAs($this->userWithPermissions('loans.view'))->post("/loans/{$loan->id}/missing/{$row->id}/found")->assertForbidden();
        $this->actingAs($this->admin())->post("/loans/{$loan->id}/missing/{$row->id}/found")->assertRedirect("/loans/returns?row={$row->id}");
        $this->actingAs($this->admin())->post('/loans/returns/stow', ['row_id' => $row->id, 'code' => 'R1'])->assertSessionHasNoErrors();

        $this->assertSame($this->shelf->id, $item->refresh()->location_id);
        $this->assertSame('found', $row->refresh()->missing_resolution);
        $this->assertSame('Alles Fehlende ist wieder da.', $loan->refresh()->defects_note);
        $this->actingAs($this->admin())->post("/loans/{$loan->id}/missing/{$row->id}/found")->assertSessionHasErrors('row');
    }

    public function test_missing_pieces_that_turn_up_go_back_with_the_rest(): void
    {
        $cables = $this->item(['kind' => 'bulk', 'quantity' => 10, 'unit' => 'Stück']);
        $loan = $this->returnedWithMissing($cables, 6, 4);
        $row = $loan->positions()->first();
        $this->assertSame(2, $row->missingItem->quantity);

        // found before the rest is put back: everything goes back together
        $this->actingAs($this->admin())->post("/loans/{$loan->id}/missing/{$row->id}/found")->assertSessionHasNoErrors();
        $this->actingAs($this->admin())->post('/loans/returns/stow', ['row_id' => $row->id])->assertSessionHasNoErrors();
        $this->assertSame(10, $cables->refresh()->quantity);
    }

    public function test_closing_the_defects_writes_off_what_is_still_missing(): void
    {
        $item = $this->item();
        $loan = $this->returnedWithMissing($item);

        $this->actingAs($this->admin())->post("/loans/{$loan->id}/close-defects", ['note' => 'Ersatz bezahlt'])->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame(RetireReason::Lost, $item->retired_reason);
        $this->assertStringContainsString('Ersatz bezahlt', $item->retired_note);
        $this->assertSame('lost', LoanItem::where('missing_item_id', $item->id)->value('missing_resolution'));
    }

    public function test_an_item_put_somewhere_by_hand_is_no_longer_missing(): void
    {
        $item = $this->item();
        $loan = $this->returnedWithMissing($item);

        $this->actingAs($this->admin())->post('/inventory/move', ['item_id' => $item->id, 'target_code' => 'R1'])->assertSessionHasNoErrors();

        $this->assertSame(0, $loan->loanItems()->stillMissing()->count());
    }
}
