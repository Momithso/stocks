<?php

namespace Modules\Loans\Tests;

use App\Models\Item;
use Modules\Loans\Support\LoanPage;

/**
 * Checklist of the borrower page: from the issue on, a box ticks its contents, shared by everyone
 * with the link.
 */
class ChecklistTest extends LoansTestCase
{
    public function test_ticking_works_from_the_issue_on_and_a_box_ticks_its_contents(): void
    {
        $confirmed = $this->confirmed([$this->boxWithContent()]);
        $this->post(LoanPage::path($confirmed).'/ticks', ['row' => 1, 'checked' => true])->assertSessionHasErrors('row');

        $loan = $this->issued([$this->boxWithContent()]);
        $row = $loan->positions()->first();

        $response = $this->postJson(LoanPage::path($loan).'/ticks', ['row' => $row->id, 'checked' => true])->assertOk();
        $this->assertTrue(collect($response->json('rows'))->every(fn ($row) => $row['checked']));
        $this->assertSame(0, $loan->loanItems()->whereNull('borrower_checked_at')->count());

        // taking back a content row takes back its box; another phone sees it on the next poll
        $child = $loan->loanItems()->whereNotNull('parent_id')->first();
        $this->postJson(LoanPage::path($loan).'/ticks', ['row' => $child->id, 'checked' => false])->assertOk();
        $this->assertNull($row->refresh()->borrower_checked_at);
        $this->getJson(LoanPage::path($loan).'/poll')->assertJsonPath('rows.0.checked', false);

        $this->postJson(LoanPage::path($loan).'/ticks', ['reset' => true])->assertOk();
        $this->assertSame(0, $loan->loanItems()->whereNotNull('borrower_checked_at')->count());
    }

    public function test_rows_of_another_loan_can_not_be_ticked(): void
    {
        $loan = $this->issued([$this->item()]);
        $other = $this->issued([$this->item()]);

        $this->postJson(LoanPage::path($loan).'/ticks', ['row' => $other->positions()->first()->id, 'checked' => true])->assertNotFound();
    }

    private function boxWithContent(): Item
    {
        $box = $this->item(['is_container' => true]);
        $this->item(['location_id' => null, 'container_id' => $box->id]);

        return $box;
    }
}
