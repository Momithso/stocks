<?php

namespace Modules\Loans\Tests;

use App\Enums\MovementType;
use App\Models\Item;
use App\Models\ItemCondition;
use Illuminate\Http\UploadedFile;
use Modules\Loans\Enums\DocumentType;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Enums\ReturnState;

/**
 * The return: complete, partial, missing, damaged, counted quantities, consumables, defects.
 */
class ReturnTest extends LoansTestCase
{
    private function signatures(): array
    {
        return ['returner' => 'Erika Muster', 'signatures' => ['returner' => self::SIGNATURE, 'receiver' => self::SIGNATURE]];
    }

    public function test_a_complete_return_closes_the_loan_with_a_receipt(): void
    {
        $item = $this->item();
        $loan = $this->issued([$item]);
        $admin = $this->admin();

        $this->actingAs($admin)->get("/loans/{$loan->id}/return")->assertOk()->assertInertia(fn ($page) => $page->component('Loans::Loans/Return', false)->has('rows', 1));
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/check", ['code' => $item->number])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/complete", $this->signatures())->assertSessionHasNoErrors();

        $loan->refresh();
        $this->assertSame(LoanStatus::Closed, $loan->status);
        $receipt = $loan->documents()->where('type', DocumentType::ReturnReceipt)->firstOrFail();
        $this->assertSame('zurück', $receipt->snapshot['rows'][0]['state']);
        $this->assertSame(MovementType::Return, $item->refresh()->movements()->first()->type);
        $this->actingAs($admin)->get("/loans/{$loan->id}/documents/{$receipt->id}")->assertOk();
        // back, not locked any more, waits to be put back
        $this->actingAs($admin)->getJson("/inventory/lookup?code={$item->number}")->assertJsonPath('notices.0.text', fn ($text) => str_contains($text, 'noch nicht eingeräumt'));
    }

    public function test_a_partial_return_keeps_the_loan_issued_and_each_part_gets_a_receipt(): void
    {
        $first = $this->item();
        $second = $this->item();
        $loan = $this->issued([$first, $second]);
        $admin = $this->admin();

        $this->actingAs($admin)->post("/loans/{$loan->id}/return/check", ['code' => $first->number]);
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/complete", $this->signatures())->assertSessionHasNoErrors();
        $this->assertSame(LoanStatus::Issued, $loan->refresh()->status);

        $this->actingAs($admin)->post("/loans/{$loan->id}/return/check", ['code' => $first->number])->assertSessionHasErrors('code');
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/check", ['code' => $second->number]);
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/complete", $this->signatures());

        $this->assertSame(LoanStatus::Closed, $loan->refresh()->status);
        $this->assertSame(2, $loan->documents()->where('type', DocumentType::ReturnReceipt)->count());
        $this->assertSame(8, $loan->signatures()->count());
    }

    public function test_missing_and_damaged_items_close_with_defects(): void
    {
        $lost = $this->item();
        $broken = $this->item();
        $loan = $this->issued([$lost, $broken]);
        $admin = $this->admin();
        $rows = $loan->positions()->get()->keyBy('item_id');

        $this->actingAs($admin)->post("/loans/{$loan->id}/return/rows/{$rows[$lost->id]->id}/defect", ['state' => 'missing', 'note' => 'nicht auffindbar'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/rows/{$rows[$broken->id]->id}/defect", ['state' => 'damaged', 'note' => 'Linse gesprungen'])->assertSessionHasErrors('condition_id');
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/rows/{$rows[$broken->id]->id}/defect", [
            'state' => 'damaged', 'note' => 'Linse gesprungen', 'condition_id' => $this->conditionId('defekt'), 'photos' => [UploadedFile::fake()->image('schaden.jpg')],
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/complete", $this->signatures())->assertSessionHasNoErrors();

        $loan->refresh();
        $this->assertSame(LoanStatus::ClosedWithDefects, $loan->status);
        $change = $broken->refresh()->conditionChanges()->first();
        $this->assertSame('defekt', $broken->condition->name);
        $this->assertSame($loan->id, $change->loan_id);
        $this->assertSame(1, $change->photos()->count());
        $this->assertNull($lost->refresh()->location_id);
        $this->actingAs($admin)->getJson('/loans/stats')->assertJsonPath('defects', 1);

        $this->actingAs($this->userWithPermissions('loans.view', 'loans.issue'))->post("/loans/{$loan->id}/close-defects", ['note' => 'Ersatz bezahlt'])->assertForbidden();
        $this->actingAs($admin)->post("/loans/{$loan->id}/close-defects", ['note' => 'Ersatz bezahlt'])->assertSessionHasNoErrors();
        $this->assertNotNull($loan->refresh()->defects_closed_at);
        $this->actingAs($admin)->getJson('/loans/stats')->assertJsonPath('defects', 0);
    }

    public function test_dirty_items_get_an_entry_with_photos_without_a_new_condition(): void
    {
        $item = $this->item();
        $loan = $this->issued([$item]);
        $row = $loan->positions()->first();

        $this->actingAs($this->admin())->post("/loans/{$loan->id}/return/rows/{$row->id}/defect", ['state' => 'dirty', 'note' => 'klebrig', 'photos' => [UploadedFile::fake()->image('dreck.jpg')]])->assertSessionHasNoErrors();

        $change = $item->refresh()->conditionChanges()->first();
        $this->assertSame($change->from_condition, $change->to_condition);
        $this->assertSame(ReturnState::Dirty, $row->refresh()->return_state);
    }

    public function test_counted_quantities_book_consumables_out_and_report_missing_parts(): void
    {
        $tape = $this->item(['kind' => 'bulk', 'quantity' => 10, 'unit' => 'Rollen', 'name' => 'Gaffa']);
        $cables = $this->item(['kind' => 'bulk', 'quantity' => 10, 'unit' => 'Stück', 'name' => 'XLR']);
        $loan = $this->issued([[$tape, 6], [$cables, 6]]);
        $rows = $loan->positions()->get()->keyBy('item_id');
        $admin = $this->admin();

        $this->actingAs($admin)->post("/loans/{$loan->id}/return/check", ['row_id' => $rows[$tape->id]->id, 'quantity' => 2])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/check", ['row_id' => $rows[$cables->id]->id, 'quantity' => 5]);
        $this->assertSame(ReturnState::Missing, $rows[$cables->id]->refresh()->return_state);
        $this->assertNull($rows[$tape->id]->refresh()->return_state);
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/complete", $this->signatures())->assertSessionHasNoErrors();

        // 4 rolls used up, the part keeps the 2 that came back
        $this->assertSame(2, $rows[$tape->id]->issuedItem->refresh()->quantity);
        $this->assertSame(MovementType::Consume, $rows[$tape->id]->issuedItem->movements()->first()->type);
        $this->assertSame(LoanStatus::ClosedWithDefects, $loan->refresh()->status);
        $receipt = $loan->documents()->where('type', DocumentType::ReturnReceipt)->firstOrFail();
        $this->assertTrue($receipt->snapshot['rows'][0]['manual']);

        // 1 cable is missing: it is split off without a place, the 5 that came back are logged
        $part = $rows[$cables->id]->issuedItem->refresh();
        $this->assertSame(5, $part->quantity);
        $this->assertSame(5, $part->movements()->where('type', MovementType::Return)->value('quantity'));
        $missing = Item::where('split_from_id', $part->id)->firstOrFail();
        $this->assertSame(1, $missing->quantity);
        $this->assertNull($missing->place());
    }

    public function test_a_defect_on_a_bulk_row_keeps_the_count(): void
    {
        $cables = $this->item(['kind' => 'bulk', 'quantity' => 10, 'unit' => 'Stück']);
        $loan = $this->issued([[$cables, 6]]);
        $row = $loan->positions()->firstOrFail();

        $this->actingAs($this->admin())->post("/loans/{$loan->id}/return/rows/{$row->id}/defect", ['state' => 'dirty', 'note' => 'staubig', 'quantity' => 4])->assertSessionHasNoErrors();
        $this->assertSame(4, $row->refresh()->returned_quantity);
        $this->assertSame('staubig 2 Stück fehlen.', $row->return_note);
    }

    public function test_a_ticked_box_takes_its_contents_along_and_a_complete_box_ticks_itself(): void
    {
        $box = $this->item(['is_container' => true]);
        $first = $this->item(['location_id' => null, 'container_id' => $box->id]);
        $second = $this->item(['location_id' => null, 'container_id' => $box->id]);
        $loan = $this->issued([$box]);
        $admin = $this->admin();

        $this->actingAs($admin)->post("/loans/{$loan->id}/return/check", ['code' => $box->number])->assertSessionHasNoErrors();
        $this->assertSame(3, $loan->loanItems()->whereNotNull('return_checked_at')->count());

        // taking one row back opens the box again
        $row = $loan->loanItems()->where('item_id', $first->id)->first();
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/rows/{$row->id}/reset");
        $this->assertNull($loan->positions()->first()->return_checked_at);

        // ticking the last open row ticks the box
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/check", ['code' => $first->number]);
        $this->assertNotNull($loan->positions()->first()->return_checked_at);
        unset($second);
    }

    public function test_a_ticked_box_takes_the_counts_of_its_contents_and_a_count_can_be_changed(): void
    {
        $box = $this->item(['is_container' => true]);
        $cables = $this->item(['kind' => 'bulk', 'quantity' => 10, 'unit' => 'Stück', 'location_id' => null, 'container_id' => $box->id]);
        $tape = $this->item(['kind' => 'bulk', 'quantity' => 4, 'unit' => 'Rollen', 'location_id' => null, 'container_id' => $box->id]);
        $loan = $this->issued([$box]);
        $rows = $loan->loanItems()->whereNotNull('parent_id')->get()->keyBy('item_id');
        $admin = $this->admin();

        $this->actingAs($admin)->post("/loans/{$loan->id}/return/check", ['code' => $box->number, 'quantities' => [$rows[$cables->id]->id => 7]])->assertSessionHasNoErrors();
        $this->assertSame(7, $rows[$cables->id]->refresh()->returned_quantity);
        $this->assertSame(ReturnState::Missing, $rows[$cables->id]->return_state);
        // without a count the full quantity
        $this->assertSame(4, $rows[$tape->id]->refresh()->returned_quantity);

        // counting again on the ticked row: all there after all
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/check", ['row_id' => $rows[$cables->id]->id, 'quantity' => 10])->assertSessionHasNoErrors();
        $this->assertSame(10, $rows[$cables->id]->refresh()->returned_quantity);
        $this->assertNull($rows[$cables->id]->return_state);

        // a wrong count in the box leaves everything open
        $second = $this->item(['is_container' => true]);
        $content = $this->item(['kind' => 'bulk', 'quantity' => 3, 'unit' => 'Stück', 'location_id' => null, 'container_id' => $second->id]);
        $other = $this->issued([$second]);
        $row = $other->loanItems()->where('item_id', $content->id)->firstOrFail();
        $this->actingAs($admin)->post("/loans/{$other->id}/return/check", ['code' => $second->number, 'quantities' => [$row->id => 9]])->assertSessionHasErrors('quantity');
        $this->assertSame(0, $other->loanItems()->whereNotNull('return_checked_at')->count());
    }

    public function test_the_scan_tab_offers_the_return(): void
    {
        $item = $this->item();
        $loan = $this->issued([$item]);

        $this->actingAs($this->admin())->getJson("/inventory/lookup?code={$item->number}")
            ->assertJsonPath('notices.0.actions.0.href', "/loans/{$loan->id}/return");
    }

    public function test_conditions_of_the_damage_list_come_from_the_settings(): void
    {
        $loan = $this->issued([$this->item()]);

        $this->actingAs($this->admin())->get("/loans/{$loan->id}/return")->assertInertia(fn ($page) => $page->has('conditions', ItemCondition::count()));
    }
}
