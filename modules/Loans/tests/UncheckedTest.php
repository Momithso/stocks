<?php

namespace Modules\Loans\Tests;

use Modules\Loans\Enums\DocumentType;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Support\Availability;

/**
 * Accepting without checking, the later inspection, and boxes that came back unchecked.
 */
class UncheckedTest extends LoansTestCase
{
    private function acceptUnchecked($loan): void
    {
        $this->actingAs($this->admin())->post("/loans/{$loan->id}/return/unchecked", [
            'returner' => 'Erika Muster',
            'signatures' => ['returner' => self::SIGNATURE, 'receiver' => self::SIGNATURE],
        ])->assertSessionHasNoErrors();
    }

    public function test_unchecked_acceptance_stops_the_time_and_waits_for_the_inspection(): void
    {
        $box = $this->item(['is_container' => true]);
        $content = $this->item(['location_id' => null, 'container_id' => $box->id]);
        $loan = $this->issued([$box], now()->subDays(3), now()->subDay());
        $this->assertTrue($loan->isOverdue());

        $this->acceptUnchecked($loan);

        $loan->refresh();
        $this->assertSame(LoanStatus::ReturnedUnchecked, $loan->status);
        $this->assertFalse($loan->isOverdue());
        $this->assertTrue($loan->documents()->where('type', DocumentType::ReturnReceipt)->firstOrFail()->snapshot['unchecked']);
        $this->actingAs($this->admin())->getJson('/loans/stats')->assertJsonPath('unchecked', 1);

        // the box can be lent again, with a hint everywhere
        $check = app(Availability::class)->check($box->refresh()->load('condition'), 1, now()->addDay(), now()->addDays(2));
        $this->assertFalse($check['blocked']);
        $this->assertStringStartsWith('Ungeprüft', $check['hints'][0]);
        $this->actingAs($this->admin())->getJson("/inventory/lookup?code={$box->number}")
            ->assertJsonPath('notices', fn ($notices) => collect($notices)->contains('badge', 'ungeprüft'));

        // the inspection: no "alles vollständig", every row by itself
        $this->actingAs($this->admin())->post("/loans/{$loan->id}/inspect/complete")->assertSessionHasErrors('loan');
        $this->actingAs($this->admin())->post("/loans/{$loan->id}/inspect/check", ['code' => $box->number]);
        $this->actingAs($this->admin())->post("/loans/{$loan->id}/inspect/complete")->assertSessionHasErrors('loan');
        $this->actingAs($this->admin())->post("/loans/{$loan->id}/inspect/check", ['code' => $content->number]);
        $this->actingAs($this->admin())->post("/loans/{$loan->id}/inspect/complete")->assertSessionHasNoErrors();

        $this->assertSame(LoanStatus::Closed, $loan->refresh()->status);
        $this->assertSame([], app(Availability::class)->check($box->refresh()->load('condition'), 1, now()->addDay(), now()->addDays(2))['hints']);
    }

    public function test_a_box_that_came_back_unchecked_does_not_tick_its_contents_in_the_next_loan(): void
    {
        $box = $this->item(['is_container' => true]);
        $this->item(['location_id' => null, 'container_id' => $box->id]);
        $first = $this->issued([$box]);
        $this->acceptUnchecked($first);
        $this->actingAs($this->admin())->post('/loans/returns/stow', ['row_id' => $first->positions()->first()->id, 'code' => $this->shelf->full_code])->assertSessionHasNoErrors();

        $second = $this->issued([$box]);
        $this->actingAs($this->admin())->post("/loans/{$second->id}/return/check", ['code' => $box->number])->assertSessionHasNoErrors();
        $this->assertSame(1, $second->loanItems()->whereNotNull('return_checked_at')->count());
    }

    public function test_the_unchecked_mark_is_on_the_material_list_of_the_next_loan(): void
    {
        $box = $this->item(['is_container' => true]);
        $first = $this->issued([$box]);
        $this->acceptUnchecked($first);
        $this->actingAs($this->admin())->post('/loans/returns/stow', ['row_id' => $first->positions()->first()->id, 'code' => $this->shelf->full_code]);

        $second = $this->issued([$box]);
        $list = $second->documents()->where('type', DocumentType::MaterialList)->firstOrFail();
        $this->assertStringStartsWith('Ungeprüft', $list->snapshot['positions'][0]['note']);
    }
}
