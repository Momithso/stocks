<?php

namespace Modules\Loans\Tests;

use Modules\Loans\Models\LoanBoxCheck;

/**
 * Pack pin on the public page of a box.
 */
class PackPinTest extends LoansTestCase
{
    public function test_the_field_shows_only_for_boxes_of_a_confirmed_loan(): void
    {
        $box = $this->item(['is_container' => true]);
        $this->get("/s/{$box->number}")->assertInertia(fn ($page) => $page->where('sections', []));

        $this->confirmed([$box]);
        $this->get("/s/{$box->number}")->assertInertia(fn ($page) => $page->where('sections.0.component', 'Loans::PublicPackPin')->where('sections.0.props.unlocked', null));
    }

    public function test_the_right_pin_shows_the_loan_and_stores_a_check_with_name(): void
    {
        $box = $this->item(['is_container' => true]);
        $content = $this->item(['location_id' => null, 'container_id' => $box->id]);
        $loan = $this->confirmed([$box]);

        $this->post("/s/{$box->number}/box-check", ['name' => 'Kim', 'ticks' => [], 'total' => 1])->assertSessionHasErrors('pin');
        $this->post("/s/{$box->number}/pack-pin", ['pin' => $loan->pack_pin])->assertSessionHasNoErrors();
        $this->get("/s/{$box->number}")->assertInertia(fn ($page) => $page->where('sections.0.props.unlocked.loan', $loan->number));

        $this->post("/s/{$box->number}/box-check", ['name' => 'Kim', 'ticks' => [$content->number], 'total' => 1])->assertSessionHasNoErrors();
        $check = LoanBoxCheck::firstOrFail();
        $this->assertSame('Kim', $check->name);
        $this->assertSame([$content->number], $check->ticks);
        $this->get("/s/{$box->number}")->assertInertia(fn ($page) => $page->where('sections.0.props.unlocked.names', ['Kim'])->has('sections.0.props.unlocked.checks', 1));

        // the issuer sees the check at the box
        $this->actingAs($this->admin())->get("/loans/{$loan->id}/issue")->assertInertia(fn ($page) => $page->where('rows.0.box_checks.0', fn ($text) => str_starts_with($text, 'kontrolliert von Kim')));
    }

    public function test_five_wrong_pins_lock_the_pin_until_a_new_one_is_set(): void
    {
        $box = $this->item(['is_container' => true]);
        $loan = $this->confirmed([$box]);
        $wrong = $loan->pack_pin === '000000' ? '111111' : '000000';

        for ($try = 0; $try < 5; $try++) {
            $this->post("/s/{$box->number}/pack-pin", ['pin' => $wrong])->assertSessionHasErrors('pin');
        }
        $this->post("/s/{$box->number}/pack-pin", ['pin' => $loan->pack_pin])->assertSessionHasErrors(['pin' => 'Die Pack-PIN ist nach zu vielen Fehlversuchen gesperrt. Jemand vom FSR muss sie neu setzen.']);

        $this->actingAs($this->admin())->post("/loans/{$loan->id}/pin", ['pin' => '246810'])->assertSessionHasNoErrors();
        auth()->logout();
        $this->post("/s/{$box->number}/pack-pin", ['pin' => '246810'])->assertSessionHasNoErrors();
    }

    public function test_the_pin_opens_only_boxes_of_its_loan(): void
    {
        $box = $this->item(['is_container' => true]);
        $other = $this->item(['is_container' => true]);
        $loan = $this->confirmed([$box]);
        $this->confirmed([$other]);

        $this->post("/s/{$other->number}/pack-pin", ['pin' => $loan->pack_pin])->assertSessionHasErrors('pin');
    }
}
