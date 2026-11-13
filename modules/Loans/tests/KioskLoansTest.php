<?php

namespace Modules\Loans\Tests;

use App\Models\User;
use App\Support\Settings;
use Database\Factories\UserFactory;
use Modules\Kiosk\Models\KioskDevice;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Models\Loan;
use Illuminate\Support\Carbon;

/**
 * Issue, return and inspection on the kiosk: the pages of the pc under /kiosk, the person of the
 * kiosk as actor, and the lock (or home page) after the task.
 */
class KioskLoansTest extends LoansTestCase
{
    private KioskDevice $device;

    private User $person;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activateModule('kiosk');
        $this->device = KioskDevice::factory()->create();
        $this->person = User::factory()->withPin()->create();
        $this->person->givePermissionTo(['kiosk.use', 'loans.view', 'loans.issue', 'inventory.view']);
        $this->asKiosk()->post('/kiosk/unlock', ['user_id' => $this->person->id, 'pin' => UserFactory::PIN]);
    }

    private function asKiosk(): static
    {
        return $this->withCookie(KioskDevice::COOKIE_NAME, $this->device->cookieValue());
    }

    public function test_the_kiosk_lists_pickups_and_issued_loans(): void
    {
        $today = $this->confirmed([$this->item()], now()->subHour());
        $later = $this->confirmed([$this->item()], now()->addDays(3));
        $out = $this->issued([$this->item()]);

        $this->asKiosk()->get('/kiosk/loans')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Loans::Kiosk', false)
            ->has('pickups', 1)
            ->where('pickups.0.id', $today->id)
            ->has('issued', 1)
            ->where('issued.0.id', $out->id));

        $this->asKiosk()->get('/kiosk/loans?show=issued')->assertInertia(fn ($page) => $page->where('show', 'issued'));
        unset($later);
    }

    public function test_the_tiles_lead_to_the_loan_tasks(): void
    {
        $this->asKiosk()->get('/kiosk/home')->assertInertia(fn ($page) => $page
            ->where('tiles', fn ($tiles) => collect($tiles)->pluck('href')->intersect(['/kiosk/loans?show=pickups', '/kiosk/loans?show=issued', '/kiosk/loans/returns'])->count() === 3));
    }

    public function test_the_tiles_say_what_happens_and_how_much_there_is_to_do(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-13 12:00:00'));

        $this->confirmed([$this->item()], now()->subHour());

        $this->asKiosk()->get('/kiosk/home')->assertInertia(fn ($page) => $page
            ->where('tiles', function ($tiles) {
                $issue = collect($tiles)->firstWhere('href', '/kiosk/loans?show=pickups');

                return $issue['section'] === 'Verleih'
                    && $issue['description'] !== null
                    && $issue['badge'] === ['text' => '1 heute', 'variant' => 'accent']
                    && collect($tiles)->firstWhere('href', '/kiosk/loans?show=issued')['badge'] === null;
            }));
    }

    public function test_a_scan_opens_the_right_step(): void
    {
        $item = $this->item();
        $confirmed = $this->confirmed([$item], now()->subHour());
        $outItem = $this->item();
        $issued = $this->issued([$outItem]);

        $this->asKiosk()->get('/kiosk/loans/find?code='.strtolower($confirmed->number))->assertRedirect("/kiosk/loans/{$confirmed->id}/issue");
        $this->asKiosk()->get("/kiosk/loans/find?code={$item->number}")->assertRedirect("/kiosk/loans/{$confirmed->id}/issue");
        $this->asKiosk()->get("/kiosk/loans/find?code={$outItem->number}")->assertRedirect("/kiosk/loans/{$issued->id}/return");
        $this->asKiosk()->from('/kiosk/loans')->get('/kiosk/loans/find?code=nichts')->assertRedirect('/kiosk/loans')->assertSessionHas('error');
    }

    public function test_the_issue_on_the_kiosk_is_done_by_the_person_and_goes_home(): void
    {
        $item = $this->item();
        $loan = $this->confirmed([$item], now()->subHour());

        $this->asKiosk()->get("/kiosk/loans/{$loan->id}/issue")->assertOk()->assertInertia(fn ($page) => $page
            ->component('Loans::Loans/Issue', false)
            ->where('surface.name', 'kiosk'));
        $this->asKiosk()->post("/kiosk/loans/{$loan->id}/issue/check", ['code' => $item->number])->assertSessionHasNoErrors();
        $this->asKiosk()->post("/kiosk/loans/{$loan->id}/issue/complete", ['signatures' => ['responsible' => self::SIGNATURE, 'issuer' => self::SIGNATURE]])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/kiosk/done');

        $loan->refresh();
        $this->assertSame(LoanStatus::Issued, $loan->status);
        $this->assertSame($this->person->id, $loan->issuer_id);

        $this->asKiosk()->get('/kiosk/done')->assertRedirect('/kiosk/home')->assertSessionHas('success', "{$loan->number} ist ausgegeben.");
        $this->asKiosk()->get('/kiosk/home')->assertOk();
    }

    public function test_the_return_on_the_kiosk_locks_when_set(): void
    {
        app(Settings::class)->set(['kiosk.lock_after_task' => true]);
        $item = $this->item();
        $loan = $this->issued([$item]);

        $this->asKiosk()->get("/kiosk/loans/{$loan->id}/return")->assertOk();
        $this->asKiosk()->post("/kiosk/loans/{$loan->id}/return/check", ['code' => $item->number])->assertSessionHasNoErrors();
        $this->asKiosk()->post("/kiosk/loans/{$loan->id}/return/complete", ['returner' => 'Erika Muster', 'signatures' => ['returner' => self::SIGNATURE, 'receiver' => self::SIGNATURE]])
            ->assertRedirect('/kiosk/done');
        $this->asKiosk()->get('/kiosk/done')->assertRedirect('/kiosk');

        $this->assertSame(LoanStatus::Closed, $loan->refresh()->status);
    }

    public function test_the_scan_card_offers_only_actions_of_the_kiosk(): void
    {
        $item = $this->item();
        $loan = $this->issued([$item]);

        $this->asKiosk()->withCredentials()->getJson("/kiosk/inventory/lookup?code={$item->number}")
            ->assertJsonPath('notices.0.href', null)
            ->assertJsonPath('notices.0.actions.0.href', "/kiosk/loans/{$loan->id}/return");
    }

    public function test_loan_pages_of_the_pc_do_not_exist_on_the_kiosk(): void
    {
        $loan = $this->confirmed([$this->item()]);
        $this->person->givePermissionTo(['loans.manage', 'loans.settings']);

        $this->asKiosk()->get("/kiosk/loans/{$loan->id}")->assertNotFound();
        $this->asKiosk()->get('/kiosk/loans/create')->assertNotFound();
        $this->asKiosk()->get('/kiosk/loans/settings')->assertNotFound();
        $this->asKiosk()->post("/kiosk/loans/{$loan->id}/cancel")->assertNotFound();
    }

    public function test_the_loan_routes_answer_404_while_the_loan_module_is_off(): void
    {
        $this->deactivateModule('loans');

        $this->asKiosk()->get('/kiosk/loans')->assertNotFound();
        $this->asKiosk()->get('/kiosk/home')->assertInertia(fn ($page) => $page
            ->where('tiles', fn ($tiles) => ! collect($tiles)->contains(fn ($tile) => str_starts_with($tile['href'], '/kiosk/loans'))));
    }

    /**
     * A loan returned with its only item missing, so it is closed with defects.
     */
    private function returnedWithMissing(): Loan
    {
        $loan = $this->issued([$this->item()]);
        $row = $loan->positions()->firstOrFail();
        $admin = $this->admin();
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/rows/{$row->id}/defect", ['state' => 'missing', 'note' => 'nicht auffindbar']);
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/complete", ['returner' => 'Erika', 'signatures' => ['returner' => self::SIGNATURE, 'receiver' => self::SIGNATURE]])->assertSessionHasNoErrors();

        return $loan->refresh();
    }

    public function test_the_return_list_shows_loans_with_open_defects(): void
    {
        $defects = $this->returnedWithMissing();
        $this->issued([$this->item()]);

        $this->asKiosk()->get('/kiosk/loans?show=issued')->assertInertia(fn ($page) => $page
            ->has('defects', 1)
            ->where('defects.0.id', $defects->id));
    }

    public function test_the_window_of_a_loan_shows_defects_and_what_the_person_may_do(): void
    {
        $loan = $this->returnedWithMissing();
        $row = $loan->positions()->firstOrFail();

        $this->asKiosk()->withCredentials()->getJson("/kiosk/loans/{$loan->id}/details")->assertOk()
            ->assertJsonPath('rows.0.state', 'missing')
            ->assertJsonPath('rows.0.note', 'nicht auffindbar')
            ->assertJsonPath('missing.0.id', $row->id)
            ->assertJsonPath('can_found', true)
            ->assertJsonPath('can_close_defects', false)
            ->assertJsonPath('href', null);

        // on the pc the window links to the loan page
        $this->actingAs($this->admin())->getJson("/loans/{$loan->id}/details")->assertJsonPath('href', "/loans/{$loan->id}");
    }

    public function test_missing_items_turn_up_again_on_the_kiosk(): void
    {
        $loan = $this->returnedWithMissing();
        $row = $loan->positions()->firstOrFail();

        $this->asKiosk()->post("/kiosk/loans/{$loan->id}/missing/{$row->id}/found")->assertRedirect("/kiosk/loans/returns?row={$row->id}");
        $this->assertSame('found', $row->refresh()->missing_resolution);
    }

    public function test_defects_are_closed_on_the_kiosk_only_with_the_permission(): void
    {
        $loan = $this->returnedWithMissing();

        $this->asKiosk()->post("/kiosk/loans/{$loan->id}/close-defects", ['note' => 'verloren'])->assertForbidden();

        $this->person->givePermissionTo('loans.cancel');
        $this->asKiosk()->withCredentials()->getJson("/kiosk/loans/{$loan->id}/details")->assertJsonPath('can_close_defects', true);
        $this->asKiosk()->from('/kiosk/loans?show=issued')->post("/kiosk/loans/{$loan->id}/close-defects", ['note' => 'verloren'])
            ->assertRedirect('/kiosk/loans?show=issued')->assertSessionHasNoErrors();
        $this->assertNotNull($loan->refresh()->defects_closed_at);
    }

    public function test_the_returns_page_gives_the_item_of_every_row(): void
    {
        $item = $this->item();
        $loan = $this->issued([$item]);
        $this->actingAs($this->admin())->post("/loans/{$loan->id}/return/check", ['code' => $item->number]);
        $this->actingAs($this->admin())->post("/loans/{$loan->id}/return/complete", ['returner' => 'Erika', 'signatures' => ['returner' => self::SIGNATURE, 'receiver' => self::SIGNATURE]]);

        $this->asKiosk()->get('/kiosk/loans/returns')->assertInertia(fn ($page) => $page->where('pending.0.item_id', $item->id));
    }

    public function test_the_rest_of_a_partial_issue_is_offered_for_the_supplement(): void
    {
        [$first, $second] = [$this->item(), $this->item()];
        $loan = $this->confirmed([$first, $second], now()->subHour());
        $this->asKiosk()->post("/kiosk/loans/{$loan->id}/issue/check", ['code' => $first->number]);
        $this->asKiosk()->post("/kiosk/loans/{$loan->id}/issue/complete", ['signatures' => ['responsible' => self::SIGNATURE, 'issuer' => self::SIGNATURE]])->assertSessionHasNoErrors();

        $this->asKiosk()->get('/kiosk/loans?show=pickups')->assertInertia(fn ($page) => $page
            ->where('pickups.0.id', $loan->id)
            ->where('pickups.0.rest', true));
        // the rest scanned: the supplement; something that is out: the return
        $this->asKiosk()->get("/kiosk/loans/find?code={$second->number}")->assertRedirect("/kiosk/loans/{$loan->id}/issue");
        $this->asKiosk()->get("/kiosk/loans/find?code={$first->number}")->assertRedirect("/kiosk/loans/{$loan->id}/return");
        $this->asKiosk()->withCredentials()->getJson("/kiosk/loans/{$loan->id}/details")->assertJsonPath('issue_href', "/kiosk/loans/{$loan->id}/issue");
    }
}
