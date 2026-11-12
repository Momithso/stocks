<?php

namespace Modules\Loans\Tests;

use Illuminate\Http\UploadedFile;
use Modules\Loans\Enums\DocumentType;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Enums\ReturnState;

/**
 * Partial issue: what is not ticked off stays as rest and leaves later with a material list of its
 * own, or is dropped. Defects found at the issue are documented, may keep a position back, and are
 * shown at the return.
 */
class PartialIssueTest extends LoansTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function signatures(): array
    {
        return ['signatures' => ['responsible' => self::SIGNATURE, 'issuer' => self::SIGNATURE]];
    }

    public function test_nothing_ticked_off_can_not_leave(): void
    {
        $loan = $this->confirmed([$this->item()], now()->subHour());

        $this->actingAs($this->admin())->post("/loans/{$loan->id}/issue/complete", $this->signatures())->assertSessionHasErrors('loan');
        $this->assertSame(LoanStatus::Confirmed, $loan->refresh()->status);
    }

    public function test_the_rest_leaves_later_with_a_supplement_list_and_without_a_new_contract(): void
    {
        [$first, $second] = [$this->item(), $this->item()];
        $loan = $this->confirmed([$first, $second], now()->subHour());
        $admin = $this->admin();

        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/check", ['code' => $first->number]);
        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/complete", $this->signatures())
            ->assertSessionHas('success', "{$loan->number} ist zum Teil ausgegeben, der Rest folgt später.");

        $loan->refresh();
        $this->assertSame(LoanStatus::Issued, $loan->status);
        $this->assertNotNull($second->refresh()->location_id, 'the rest stays at its place');
        $this->assertSame(['contract', 'list'], $loan->documents()->pluck('type')->map->value->sort()->values()->all());
        $this->assertSame([$first->number], array_column($loan->documents()->where('type', 'list')->first()->snapshot['positions'], 'number'));

        // the rest is not part of the return, the loan stays open while the rest may still leave
        $this->actingAs($admin)->get("/loans/{$loan->id}/return")->assertInertia(fn ($page) => $page->has('rows', 1));
        $this->actingAs($admin)->get("/loans/{$loan->id}")->assertInertia(fn ($page) => $page->where('hasRest', true));

        // the supplement: same steps, a material list of its own, signed again, no contract
        $this->actingAs($admin)->get("/loans/{$loan->id}/issue")->assertOk()->assertInertia(fn ($page) => $page
            ->where('loan.supplement', true)
            ->has('rows', 1));
        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/check", ['code' => $second->number]);
        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/complete", $this->signatures())->assertSessionHas('success', "{$loan->number} ist ausgegeben.");

        $lists = $loan->documents()->where('type', DocumentType::MaterialList)->orderBy('id')->get();
        $this->assertCount(2, $lists);
        $this->assertTrue($lists[1]->snapshot['supplement']);
        $this->assertSame([$second->number], array_column($lists[1]->snapshot['positions'], 'number'));
        $this->assertSame(1, $loan->documents()->where('type', DocumentType::Contract)->count());
        $this->assertSame(2, $lists[1]->signatures()->count());
        $this->assertFalse($loan->positions()->whereNull('issued_at')->exists());
        $this->assertSame($lists[1]->id, $loan->positions()->where('item_id', $second->id)->value('issue_document_id'));
    }

    public function test_fewer_pieces_split_the_position_and_unticking_joins_it_again(): void
    {
        $benches = $this->item(['kind' => 'bulk', 'quantity' => 40, 'unit' => 'Stück']);
        $loan = $this->confirmed([[$benches, 10]], now()->subHour());
        $admin = $this->admin();
        $position = $loan->positions()->first();

        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/check", ['row_id' => $position->id, 'quantity' => 7])->assertSessionHasNoErrors();
        $this->assertSame([7, 3], $loan->positions()->orderBy('id')->pluck('quantity')->all());

        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/rows/{$position->id}/uncheck");
        $this->assertSame([10], $loan->positions()->pluck('quantity')->all());

        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/check", ['row_id' => $position->id, 'quantity' => 7]);
        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/complete", $this->signatures())->assertSessionHasNoErrors();

        $this->assertSame(33, $benches->refresh()->quantity);
        $rest = $loan->positions()->whereNull('issued_at')->first();
        $this->assertSame(3, $rest->quantity);
        // the rest is no defect: the return of the 7 closes nothing while the rest may still leave
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/check", ['row_id' => $position->id, 'quantity' => 7]);
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/complete", ['returner' => 'Erika', 'signatures' => ['returner' => self::SIGNATURE, 'receiver' => self::SIGNATURE]])->assertSessionHasNoErrors();
        $this->assertSame(LoanStatus::Issued, $loan->refresh()->status);

        // dropping the rest closes the loan without defects
        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/rows/{$rest->id}/drop")->assertSessionHasNoErrors();
        $this->assertSame(LoanStatus::Closed, $loan->refresh()->status);
    }

    public function test_a_defect_found_at_the_issue_is_documented_and_shown_at_the_return(): void
    {
        $beamer = $this->item(['name' => 'Beamer']);
        $loan = $this->confirmed([$beamer], now()->subHour());
        $admin = $this->admin();
        $row = $loan->positions()->first();
        $this->actingAs($admin)->get("/loans/{$loan->id}/issue");

        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/rows/{$row->id}/defect", ['state' => 'missing', 'note' => 'x'])->assertSessionHasErrors('state');
        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/rows/{$row->id}/defect", ['state' => 'damaged', 'note' => 'Kratzer'])->assertSessionHasErrors('condition_id');
        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/rows/{$row->id}/defect", [
            'state' => 'damaged', 'note' => 'Kratzer am Gehäuse', 'condition_id' => $this->conditionId('defekt'), 'photos' => [UploadedFile::fake()->image('kratzer.jpg')],
        ])->assertSessionHasNoErrors();

        $this->assertSame('defekt', $beamer->refresh()->condition->name);
        $this->assertSame($loan->id, $beamer->conditionChanges()->latest('id')->value('loan_id'));
        $this->assertSame('Beschädigt bei der Ausgabe von '.$loan->number.': Kratzer am Gehäuse', $beamer->conditionChanges()->latest('id')->value('reason'));

        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/check", ['code' => $beamer->number]);
        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/complete", $this->signatures())->assertSessionHasNoErrors();

        $list = $loan->documents()->where('type', 'list')->first();
        $this->assertSame('Mangel bei der Ausgabe: beschädigt: Kratzer am Gehäuse', $list->snapshot['positions'][0]['note']);
        $this->actingAs($admin)->get("/loans/{$loan->id}/return")->assertInertia(fn ($page) => $page->where('rows.0.issue_defect', 'beschädigt: Kratzer am Gehäuse'));

        // returned without new defect: the loan closes without defects, the receipt names the old one
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/check", ['code' => $beamer->number]);
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/complete", ['returner' => 'Erika', 'signatures' => ['returner' => self::SIGNATURE, 'receiver' => self::SIGNATURE]]);
        $this->assertSame(LoanStatus::Closed, $loan->refresh()->status);
        $receipt = $loan->documents()->where('type', 'return_receipt')->first();
        $this->assertSame('schon bei der Ausgabe: beschädigt: Kratzer am Gehäuse', $receipt->snapshot['rows'][0]['note']);
    }

    public function test_a_position_with_a_defect_can_be_kept_back_as_rest(): void
    {
        [$good, $dirty] = [$this->item(), $this->item()];
        $loan = $this->confirmed([$good, $dirty], now()->subHour());
        $admin = $this->admin();
        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/check", ['code' => $good->number]);
        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/check", ['code' => $dirty->number]);
        $row = $loan->positions()->where('item_id', $dirty->id)->first();

        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/rows/{$row->id}/defect", ['state' => 'dirty', 'note' => 'voller Kleber', 'keep_back' => true])->assertSessionHasNoErrors();

        $row->refresh();
        $this->assertNull($row->checked_at);
        $this->assertSame(ReturnState::Dirty, $row->issue_state);

        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/complete", $this->signatures())->assertSessionHasNoErrors();
        $this->assertNull($row->refresh()->issued_at);
        $this->assertNotNull($loan->positions()->where('item_id', $good->id)->value('issued_at'));
    }

    public function test_incomplete_is_a_defect_at_the_return(): void
    {
        $item = $this->item();
        $loan = $this->issued([$item]);
        $row = $loan->positions()->first();
        $admin = $this->admin();

        $this->actingAs($admin)->post("/loans/{$loan->id}/return/rows/{$row->id}/defect", ['state' => 'incomplete', 'note' => 'Kabel fehlt'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/complete", ['returner' => 'Erika', 'signatures' => ['returner' => self::SIGNATURE, 'receiver' => self::SIGNATURE]]);

        $this->assertSame(LoanStatus::ClosedWithDefects, $loan->refresh()->status);
        $this->assertSame('Unvollständig zurück aus '.$loan->number.': Kabel fehlt', $item->conditionChanges()->latest('id')->value('reason'));
    }

    public function test_an_issued_loan_without_rest_can_not_be_issued_again(): void
    {
        $loan = $this->issued([$this->item()]);

        $this->actingAs($this->admin())->post("/loans/{$loan->id}/issue/complete", $this->signatures())->assertSessionHasErrors('loan');
    }
}
