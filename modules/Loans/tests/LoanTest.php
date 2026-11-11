<?php

namespace Modules\Loans\Tests;

use App\Models\User;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Models\Loan;

/**
 * Loans: create, positions, confirm, cancel, extend, own use, numbers.
 */
class LoanTest extends LoansTestCase
{
    public function test_a_loan_is_created_as_draft_with_a_number_of_the_year(): void
    {
        $user = $this->userWithPermissions('loans.view', 'loans.manage');
        $contact = $this->contact();

        $this->actingAs($user)->post('/loans', [
            'kind' => 'external',
            'responsible_contact_id' => $contact->id,
            'pickup_at' => now()->addDay()->toDateTimeString(),
            'return_at' => now()->addDays(2)->toDateTimeString(),
            'purpose' => 'Erstiparty',
        ])->assertRedirect();

        $loan = Loan::firstOrFail();
        $this->assertSame(LoanStatus::Draft, $loan->status);
        $this->assertSame('B-'.now()->year.'-0001', $loan->number);
        $this->assertSame('B-'.now()->year.'-0002', $this->draft()->number);
        $this->assertSame($user->id, $loan->created_by);
    }

    public function test_the_return_must_be_after_the_pickup(): void
    {
        $this->actingAs($this->admin())->post('/loans', [
            'kind' => 'external', 'pickup_at' => now()->addDays(2)->toDateTimeString(), 'return_at' => now()->addDay()->toDateTimeString(),
        ])->assertSessionHasErrors('return_at');
    }

    public function test_positions_are_added_by_id_and_by_scanned_code(): void
    {
        $loan = $this->draft();
        $item = $this->item();
        $bulk = $this->item(['kind' => 'bulk', 'quantity' => 40, 'unit' => 'Stück']);
        $admin = $this->admin();

        $this->actingAs($admin)->post("/loans/{$loan->id}/positions", ['item_id' => $item->id])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/loans/{$loan->id}/positions", ['code' => "https://stocks.example/s/{$bulk->number}", 'quantity' => 20])->assertSessionHasNoErrors();
        // the same bulk item again: the quantities add up
        $this->actingAs($admin)->post("/loans/{$loan->id}/positions", ['item_id' => $bulk->id, 'quantity' => 5]);
        // a single piece only once
        $this->actingAs($admin)->post("/loans/{$loan->id}/positions", ['item_id' => $item->id])->assertSessionHasErrors('item');

        $this->assertSame(2, $loan->positions()->count());
        $this->assertSame(25, $loan->positions()->where('item_id', $bulk->id)->value('quantity'));
    }

    public function test_retired_and_defective_items_are_not_lendable(): void
    {
        $loan = $this->draft();
        $defect = $this->item(['condition_id' => $this->conditionId('defekt')]);
        $retired = $this->item(['retired_at' => now()]);

        $this->actingAs($this->admin())->post("/loans/{$loan->id}/positions", ['item_id' => $defect->id])->assertSessionHasErrors(['item' => "{$defect->number} ist defekt und lässt sich nicht verleihen."]);
        $this->actingAs($this->admin())->post("/loans/{$loan->id}/positions", ['item_id' => $retired->id])->assertSessionHasErrors('item');
    }

    public function test_a_box_brings_its_contents_and_its_contents_can_not_be_added_on_their_own(): void
    {
        $box = $this->item(['is_container' => true, 'name' => 'Kabelkiste']);
        $inside = $this->item(['location_id' => null, 'container_id' => $box->id, 'name' => 'XLR']);
        $loan = $this->draft([$box]);

        $this->actingAs($this->admin())->post("/loans/{$loan->id}/positions", ['item_id' => $inside->id])->assertSessionHasErrors('item');
        $this->actingAs($this->admin())->get("/loans/{$loan->id}")
            ->assertInertia(fn ($page) => $page->where('contents.0.name', 'Kabelkiste')->where('contents.1.name', 'XLR')->where('contents.1.depth', 1));
    }

    public function test_confirming_needs_a_complete_responsible_person_and_positions(): void
    {
        $loan = $this->draft();
        $this->actingAs($this->admin())->post("/loans/{$loan->id}/confirm")->assertSessionHasErrors('loan');

        $loan = $this->draft([$this->item()]);
        $loan->responsible->update(['phone' => null]);
        $this->actingAs($this->admin())->post("/loans/{$loan->id}/confirm")->assertSessionHasErrors(['loan' => 'Bei Erika Muster fehlen noch: Telefon.']);

        $loan->responsible->update(['phone' => '0241']);
        $this->actingAs($this->admin())->post("/loans/{$loan->id}/confirm")->assertSessionHasNoErrors();
        $loan->refresh();
        $this->assertSame(LoanStatus::Confirmed, $loan->status);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $loan->pack_pin);
    }

    public function test_cancelling_needs_a_reason_and_the_permission(): void
    {
        $loan = $this->confirmed([$this->item()]);

        $this->actingAs($this->userWithPermissions('loans.view', 'loans.manage'))->post("/loans/{$loan->id}/cancel", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->admin())->post("/loans/{$loan->id}/cancel", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->admin())->post("/loans/{$loan->id}/cancel", ['reason' => 'Fest fällt aus'])->assertSessionHasNoErrors();

        $loan->refresh();
        $this->assertSame(LoanStatus::Cancelled, $loan->status);
        $this->assertSame('Fest fällt aus', $loan->cancelled_reason);
        // cancelled loans hold nothing
        $this->confirmed([$loan->positions()->first()->item]);
    }

    public function test_only_drafts_are_deleted(): void
    {
        $draft = $this->draft();
        $confirmed = $this->confirmed([$this->item()]);

        $this->actingAs($this->admin())->delete("/loans/{$confirmed->id}")->assertSessionHasErrors('loan');
        $this->actingAs($this->admin())->delete("/loans/{$draft->id}")->assertRedirect('/loans');
        $this->assertModelMissing($draft);
    }

    public function test_an_issued_loan_is_extended_if_no_following_loan_collides(): void
    {
        $item = $this->item();
        $loan = $this->issued([$item], now()->subHour(), now()->addDay());
        $this->confirmed([$item], now()->addDays(3), now()->addDays(4));

        $this->actingAs($this->admin())->post("/loans/{$loan->id}/extend", ['return_at' => now()->addDays(5)->toDateTimeString()])->assertSessionHasErrors('return_at');
        $this->actingAs($this->admin())->post("/loans/{$loan->id}/extend", ['return_at' => now()->addDays(2)->toDateTimeString()])->assertSessionHasNoErrors();
        $this->assertTrue($loan->refresh()->return_at->isSameDay(now()->addDays(2)));
    }

    public function test_times_and_positions_are_fixed_after_the_issue(): void
    {
        $loan = $this->issued([$this->item()]);

        $this->actingAs($this->admin())->put("/loans/{$loan->id}", [
            'kind' => 'external', 'responsible_contact_id' => $loan->responsible_contact_id,
            'pickup_at' => now()->addDays(3)->toDateTimeString(), 'return_at' => now()->addDays(4)->toDateTimeString(),
        ])->assertSessionHasErrors('pickup_at');
        $this->actingAs($this->admin())->post("/loans/{$loan->id}/positions", ['item_id' => $this->item()->id])->assertSessionHasErrors('item');
    }

    public function test_own_use_needs_a_member_and_has_no_contract(): void
    {
        $member = User::factory()->create();
        $item = $this->item();
        $loan = $this->draft([$item], data: ['kind' => 'internal', 'responsible_user_id' => $member->id]);

        $this->assertNull($loan->responsible_contact_id);
        $this->actingAs($this->admin())->post("/loans/{$loan->id}/confirm")->assertSessionHasNoErrors();
        $this->actingAs($this->admin())->get("/loans/{$loan->id}/issue")->assertOk();
        $this->actingAs($this->admin())->post("/loans/{$loan->id}/issue/check", ['code' => $item->number]);
        $this->actingAs($this->admin())->post("/loans/{$loan->id}/issue/complete", [])->assertSessionHasNoErrors();

        $loan->refresh();
        $this->assertSame(LoanStatus::Issued, $loan->status);
        $this->assertSame(['list'], $loan->documents()->pluck('type')->map->value->all());
        $this->assertSame(0, $loan->signatures()->count());
        $this->actingAs($this->admin())->get("/loans/{$loan->id}/preview/contract.pdf")->assertNotFound();
    }

    public function test_free_members_see_loans_but_do_not_issue(): void
    {
        $loan = $this->confirmed([$this->item()]);
        $free = $this->userWithRoles('Freies Mitglied');

        $this->actingAs($free)->get('/loans')->assertOk();
        $this->actingAs($free)->get("/loans/{$loan->id}")->assertOk();
        $this->actingAs($free)->get("/loans/{$loan->id}/issue")->assertForbidden();
        $this->actingAs($free)->post('/loans', [])->assertForbidden();
    }

    public function test_the_list_filters_overdue_loans(): void
    {
        $overdue = $this->issued([$this->item()], now()->subDays(3), now()->subDay());
        $this->confirmed([$this->item()]);

        $this->assertTrue($overdue->isOverdue());
        $this->assertSame(1, $overdue->overdueDays());
        $this->actingAs($this->admin())->get('/loans?status=overdue')->assertOk()
            ->assertInertia(fn ($page) => $page->has('loans.data', 1)->where('loans.data.0.id', $overdue->id));
        $this->actingAs($this->admin())->getJson('/loans/stats')->assertOk()->assertJsonPath('overdue_count', 1)->assertJsonPath('unchecked', 0);
    }
}
