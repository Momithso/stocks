<?php

namespace Modules\Loans\Tests;

use App\Models\Item;
use App\Models\ItemCondition;
use Illuminate\Validation\ValidationException;
use Modules\Loans\Actions\ChangePositions;
use Modules\Loans\Actions\IssueLoan;
use Modules\Loans\Actions\SaveLoan;
use Modules\Loans\Enums\DocumentType;
use Modules\Loans\Enums\LoanKind;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Http\KioskLoanController;
use Modules\Loans\Models\Loan;

/**
 * Safeguards from the review of phases 3 and 4: tasks sent twice, rests and loans that missed their
 * time, fixed data after the issue, who sees signed papers, boxes and their contents, bookings
 * that ask before an item is taken from them.
 */
class LoanGuardsTest extends LoansTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function issueSignatures(): array
    {
        return ['signatures' => ['responsible' => self::SIGNATURE, 'issuer' => self::SIGNATURE]];
    }

    /**
     * @return array<string, mixed>
     */
    private function returnSignatures(): array
    {
        return ['returner' => 'Erika Muster', 'signatures' => ['returner' => self::SIGNATURE, 'receiver' => self::SIGNATURE]];
    }

    public function test_an_issue_sent_twice_is_booked_once(): void
    {
        $item = $this->item();
        $loan = $this->confirmed([$item], now()->subHour());
        $admin = $this->admin();

        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/check", ['code' => $item->number]);
        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/complete", $this->issueSignatures())->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/complete", $this->issueSignatures())->assertSessionHasErrors('loan');

        $this->assertSame(1, $loan->documents()->where('type', DocumentType::Contract)->count());
        $this->assertSame(1, $item->refresh()->movements()->where('type', 'issue')->count());
    }

    public function test_a_return_sent_twice_is_booked_once(): void
    {
        $item = $this->item();
        $loan = $this->issued([$item]);
        $admin = $this->admin();

        $this->actingAs($admin)->post("/loans/{$loan->id}/return/check", ['code' => $item->number]);
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/complete", $this->returnSignatures())->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/complete", $this->returnSignatures())->assertSessionHasErrors('loan');

        $this->assertSame(1, $loan->documents()->where('type', DocumentType::ReturnReceipt)->count());
    }

    public function test_a_rest_that_missed_its_time_holds_nothing_and_can_only_be_dropped(): void
    {
        [$taken, $rest] = [$this->item(), $this->item()];
        $loan = $this->confirmed([$taken, $rest], now()->subDays(3), now()->subDay()->subHour());
        $admin = $this->admin();
        $issue = app(IssueLoan::class);

        // issued in its time, only one of the two
        $this->travelTo(now()->subDays(2));
        $issue->check($admin, $loan, $taken->number, null);
        $issue->complete($admin, $loan, ['responsible' => self::SIGNATURE, 'issuer' => self::SIGNATURE], false, null, 'PHPUnit');
        $this->travelBack();

        // the part that is out keeps the loan overdue, the rest is free for others
        $this->assertTrue($loan->refresh()->isOverdue());
        $this->confirmed([$rest]);
        $this->assertTrue(IssueLoan::restExpired($loan));
        $this->assertFalse(KioskLoanController::pickups()->whereKey($loan->id)->exists());

        // nothing leaves any more, the rest can be dropped
        $row = $loan->positions()->whereNull('issued_at')->firstOrFail();
        $this->actingAs($admin)->get("/loans/{$loan->id}/issue")->assertOk()->assertInertia(fn ($page) => $page->where('loan.expired', true));
        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/check", ['row_id' => $row->id])->assertSessionHasErrors('loan');
        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/rows/{$row->id}/drop")->assertSessionHasNoErrors();

        // only the rest left: nothing is out, so not overdue
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/check", ['code' => $taken->number]);
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/complete", $this->returnSignatures());
        $this->assertSame(LoanStatus::Closed, $loan->refresh()->status);
    }

    public function test_an_issued_loan_whose_rest_is_all_that_is_left_is_not_overdue(): void
    {
        [$taken, $rest] = [$this->item(), $this->item()];
        $loan = $this->confirmed([$taken, $rest], now()->subDays(3), now()->subDay());
        $admin = $this->admin();

        $this->travelTo(now()->subDays(2));
        app(IssueLoan::class)->check($admin, $loan, $taken->number, null);
        app(IssueLoan::class)->complete($admin, $loan, ['responsible' => self::SIGNATURE, 'issuer' => self::SIGNATURE], false, null, 'PHPUnit');
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/check", ['code' => $taken->number]);
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/complete", $this->returnSignatures());
        $this->travelBack();

        $loan->refresh();
        $this->assertSame(LoanStatus::Issued, $loan->status);
        $this->assertFalse($loan->isOverdue());
        $this->assertFalse(Loan::overdue()->whereKey($loan->id)->exists());
    }

    public function test_a_confirmed_loan_that_was_not_picked_up_holds_nothing(): void
    {
        $item = $this->item();
        $loan = $this->confirmed([$item], now()->subDays(2), now()->subDay());

        $this->assertTrue($loan->isNotPickedUp());
        $this->assertSame('Nicht abgeholt', $loan->summary()['status_label']);
        $this->assertFalse(KioskLoanController::pickups()->whereKey($loan->id)->exists());
        $this->confirmed([$item]);

        $row = $loan->positions()->firstOrFail();
        $this->actingAs($this->admin())->post("/loans/{$loan->id}/issue/check", ['row_id' => $row->id])
            ->assertSessionHasErrors(['loan' => 'Der Rückgabetermin ist vorbei, die Buchung wurde nicht abgeholt. Ändere erst den Zeitraum oder storniere sie.']);
    }

    public function test_a_missed_pickup_shows_on_the_kiosk_while_the_time_runs(): void
    {
        $loan = $this->confirmed([$this->item()], now()->subDay(), now()->addDay());

        $this->assertTrue(KioskLoanController::pickups()->whereKey($loan->id)->exists());
    }

    public function test_after_the_issue_only_purpose_notes_and_event_change(): void
    {
        $loan = $this->issued([$this->item()]);
        $admin = $this->admin();
        $data = [
            'kind' => LoanKind::External->value,
            'responsible_contact_id' => $loan->responsible_contact_id,
            'borrower_contact_id' => $loan->borrower_contact_id,
            'pickup_at' => $loan->pickup_at->toDateTimeString(),
            'return_at' => $loan->return_at->toDateTimeString(),
        ];

        app(SaveLoan::class)->handle($admin, $loan, [...$data, 'purpose' => 'Neuer Anlass', 'notes' => 'Notiz']);
        $this->assertSame('Neuer Anlass', $loan->refresh()->purpose);

        foreach ([['kind' => LoanKind::Internal->value, 'responsible_user_id' => $admin->id], ['responsible_contact_id' => $this->contact()->id], ['borrower_contact_id' => $this->contact()->id]] as $change) {
            try {
                app(SaveLoan::class)->handle($admin, $loan->refresh(), [...$data, ...$change]);
                $this->fail('The change must be refused: '.json_encode($change));
            } catch (ValidationException $exception) {
                $this->assertStringContainsString('steht seit der Ausgabe fest', implode(' ', $exception->validator->errors()->all()));
            }
        }
        $this->assertSame(LoanKind::External, $loan->refresh()->kind);
    }

    public function test_signed_papers_are_only_for_those_who_issue_or_manage(): void
    {
        $loan = $this->issued([$this->item()]);
        $contract = $loan->documents()->where('type', DocumentType::Contract)->firstOrFail();
        $free = $this->userWithRoles('Freies Mitglied');

        $this->actingAs($free)->get("/loans/{$loan->id}")->assertOk()->assertInertia(fn ($page) => $page->where('documents', []));
        $this->actingAs($free)->get("/loans/{$loan->id}/documents/{$contract->id}")->assertForbidden();
        $this->actingAs($this->admin())->get("/loans/{$loan->id}")->assertInertia(fn ($page) => $page->has('documents', 2));
    }

    public function test_a_box_can_not_join_a_loan_that_has_one_of_its_contents(): void
    {
        $box = $this->item(['is_container' => true]);
        $inside = $this->item(['location_id' => null, 'container_id' => $box->id]);
        $loan = $this->draft([$inside]);

        $this->expectExceptionMessage("In {$box->number} liegt {$inside->number}, das schon in der Buchung ist.");
        app(ChangePositions::class)->add($loan, $box->load('condition'));
    }

    public function test_a_box_that_came_back_unchecked_leaves_with_its_contents_ticked_one_by_one(): void
    {
        $box = $this->item(['is_container' => true]);
        $inside = $this->item(['location_id' => null, 'container_id' => $box->id]);
        $first = $this->issued([$box]);
        $admin = $this->admin();
        $this->actingAs($admin)->post("/loans/{$first->id}/return/unchecked", $this->returnSignatures())->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/loans/returns/stow', ['row_id' => $first->positions()->first()->id, 'code' => $this->shelf->full_code])->assertSessionHasNoErrors();

        $second = $this->confirmed([$box], now()->subHour());
        app(IssueLoan::class)->prepare($second);
        $this->actingAs($admin)->post("/loans/{$second->id}/issue/check", ['code' => $box->number])->assertSessionHasErrors('code');
        $this->actingAs($admin)->post("/loans/{$second->id}/issue/check", ['code' => $inside->number])->assertSessionHasNoErrors();
        $this->assertNotNull($second->positions()->first()->checked_at, 'the box is ticked with its contents');

        // something put into the box after the tick and not ticked stays here, at the place of the box
        $later = $this->item(['location_id' => null, 'container_id' => $box->id]);
        $this->actingAs($admin)->post("/loans/{$second->id}/issue/complete", $this->issueSignatures())->assertSessionHasNoErrors();
        $this->assertNull($later->refresh()->container_id);
        $this->assertSame($box->id, $inside->refresh()->container_id);
    }

    public function test_the_last_position_of_a_confirmed_loan_is_cancelled_not_dropped(): void
    {
        $loan = $this->confirmed([$this->item()], now()->subHour());
        $row = $loan->positions()->firstOrFail();

        $this->actingAs($this->admin())->post("/loans/{$loan->id}/issue/rows/{$row->id}/drop")->assertSessionHasErrors('loan');
        $this->assertTrue($loan->positions()->exists());
    }

    public function test_the_label_of_the_origin_finds_the_part_that_left(): void
    {
        $item = $this->item(Item::factory()->bulk(10)->raw(['location_id' => $this->shelf->id]));
        $loan = $this->issued([[$item, 4]]);
        $this->assertNotNull($loan->positions()->first()->issued_item_id);

        $this->actingAs($this->admin())->post("/loans/{$loan->id}/return/check", ['code' => $item->number, 'quantity' => 4])->assertSessionHasNoErrors();
        $this->assertNotNull($loan->positions()->first()->return_checked_at);
    }

    public function test_the_return_page_of_a_loan_without_return_goes_back_with_a_reason(): void
    {
        $loan = $this->confirmed([$this->item()]);

        $this->actingAs($this->admin())->get("/loans/{$loan->id}/return")->assertRedirect("/loans/{$loan->id}")->assertSessionHas('warning');
    }

    public function test_an_incomplete_part_keeps_the_defects_open_when_the_missing_one_turns_up(): void
    {
        [$missing, $incomplete] = [$this->item(), $this->item()];
        $loan = $this->issued([$missing, $incomplete]);
        $admin = $this->admin();
        [$missingRow, $incompleteRow] = $loan->positions()->get()->all();

        $this->actingAs($admin)->post("/loans/{$loan->id}/return/rows/{$missingRow->id}/defect", ['state' => 'missing', 'note' => 'weg']);
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/rows/{$incompleteRow->id}/defect", ['state' => 'incomplete', 'note' => 'Kabel fehlt']);
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/complete", $this->returnSignatures())->assertSessionHasNoErrors();
        $this->assertSame(LoanStatus::ClosedWithDefects, $loan->refresh()->status);

        $this->actingAs($admin)->post("/loans/{$loan->id}/missing/{$missingRow->id}/found")->assertSessionHasNoErrors();
        $this->assertNull($loan->refresh()->defects_closed_at);
    }

    public function test_taking_a_booked_item_from_its_loan_asks_first(): void
    {
        $item = $this->item();
        $consumable = $this->item(Item::factory()->bulk(10, 'Stück')->raw(['location_id' => $this->shelf->id]));
        $loan = $this->confirmed([$item, [$consumable, 4]]);
        $admin = $this->admin();
        $defect = ItemCondition::where('is_defect', true)->firstOrFail();

        $this->actingAs($admin)->post("/inventory/items/{$item->id}/retire", ['reason' => 'defective'])
            ->assertSessionHasErrors(['confirm' => "{$item->number} ist gebucht: {$loan->number}, ab {$loan->pickup_at->format('d.m., H:i')} Uhr. Trotzdem ausbuchen?"]);
        $this->assertNull($item->refresh()->retired_at);
        $this->actingAs($admin)->post("/inventory/items/{$item->id}/condition", ['condition_id' => $defect->id, 'reason' => 'Bruch'])->assertSessionHasErrors('confirm');
        $this->actingAs($admin)->post('/inventory/move', ['item_id' => $consumable->id, 'target_code' => $this->shelf->full_code, 'quantity' => 2])->assertSessionHasErrors('confirm');

        // answered "Ja, trotzdem"
        $this->actingAs($admin)->post("/inventory/items/{$item->id}/condition", ['condition_id' => $defect->id, 'reason' => 'Bruch', 'confirmed' => true])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/inventory/items/{$item->id}/retire", ['reason' => 'defective', 'confirmed' => true])->assertSessionHasNoErrors();
        $this->assertNotNull($item->refresh()->retired_at);
    }

    public function test_an_item_that_is_not_booked_is_taken_without_asking(): void
    {
        $item = $this->item();

        $this->actingAs($this->admin())->post("/inventory/items/{$item->id}/retire", ['reason' => 'defective'])->assertSessionHasNoErrors();
    }
}
