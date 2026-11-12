<?php

namespace Modules\Loans\Tests;

use Illuminate\Support\Facades\Notification;
use Modules\Loans\Models\LoanExtensionRequest;
use Modules\Loans\Notifications\ExtensionDecided;
use Modules\Loans\Notifications\ExtensionRequested;
use Modules\Loans\Support\LoanPage;

/**
 * Extension request of the borrower page: ask, accept, collision, decline, one open at a time.
 */
class ExtensionRequestTest extends LoansTestCase
{
    public function test_the_borrower_asks_and_a_member_accepts(): void
    {
        $issuer = $this->userWithRoles('FSR-Mitglied');
        $loan = $this->issued([$this->item()], now()->subHour(), now()->addDay(), $issuer);
        $wanted = now()->addDays(3)->setTime(12, 0);

        $this->post(LoanPage::path($loan).'/extension', ['return_at' => $wanted->format('Y-m-d\TH:i'), 'note' => 'Feier verschoben'])->assertSessionHasNoErrors();
        Notification::assertSentTo($issuer, ExtensionRequested::class);
        // only one open request
        $this->post(LoanPage::path($loan).'/extension', ['return_at' => $wanted->copy()->addDay()->format('Y-m-d\TH:i')])->assertSessionHasErrors('return_at');

        $request = LoanExtensionRequest::firstOrFail();
        $this->actingAs($issuer)->get("/loans/{$loan->id}")->assertInertia(fn ($page) => $page->where('borrowerPage.extension.id', $request->id));
        $this->post("/loans/{$loan->id}/extension-requests/{$request->id}/accept")->assertSessionHasNoErrors();

        $this->assertSame($wanted->format('Y-m-d H:i'), $loan->refresh()->return_at->format('Y-m-d H:i'));
        $this->assertSame(LoanExtensionRequest::ACCEPTED, $request->refresh()->status);
        Notification::assertSentTo($loan, ExtensionDecided::class);
        $this->assertSame(2, $loan->messages()->where('sender', 'system')->count());
    }

    public function test_a_collision_keeps_the_request_open(): void
    {
        $item = $this->item();
        $loan = $this->issued([$item], now()->subHour(), now()->addDay());
        $this->confirmed([$item], now()->addDays(2), now()->addDays(3));

        $this->post(LoanPage::path($loan).'/extension', ['return_at' => now()->addDays(4)->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();
        $request = LoanExtensionRequest::firstOrFail();

        $this->actingAs($this->admin())->post("/loans/{$loan->id}/extension-requests/{$request->id}/accept")->assertSessionHasErrors('return_at');
        $this->assertSame(LoanExtensionRequest::OPEN, $request->refresh()->status);
    }

    public function test_a_member_declines_with_an_answer(): void
    {
        $loan = $this->issued([$this->item()], now()->subHour(), now()->addDay());
        $this->post(LoanPage::path($loan).'/extension', ['return_at' => now()->addDays(2)->format('Y-m-d\TH:i')]);
        $request = LoanExtensionRequest::firstOrFail();

        $this->actingAs($this->admin())->post("/loans/{$loan->id}/extension-requests/{$request->id}/decline")->assertSessionHasErrors('answer');
        $this->post("/loans/{$loan->id}/extension-requests/{$request->id}/decline", ['answer' => 'Wird am Freitag gebraucht.'])->assertSessionHasNoErrors();
        $this->assertSame(LoanExtensionRequest::DECLINED, $request->refresh()->status);
        Notification::assertSentTo($loan, ExtensionDecided::class);

        // a new request is possible again
        $this->get(LoanPage::path($loan))->assertInertia(fn ($page) => $page->where('loan.can_extend', true)->where('loan.extension.status', 'declined'));
    }

    public function test_only_an_issued_loan_can_be_extended(): void
    {
        $loan = $this->confirmed([$this->item()]);
        $this->post(LoanPage::path($loan).'/extension', ['return_at' => now()->addDays(5)->format('Y-m-d\TH:i')])->assertSessionHasErrors('return_at');
    }

    public function test_a_second_click_on_a_decision_finds_it_decided(): void
    {
        $loan = $this->issued([$this->item()], now()->subHour(), now()->addDay());
        $this->post(LoanPage::path($loan).'/extension', ['return_at' => now()->addDays(2)->format('Y-m-d\TH:i')]);
        $request = LoanExtensionRequest::firstOrFail();
        $admin = $this->admin();

        $this->actingAs($admin)->post("/loans/{$loan->id}/extension-requests/{$request->id}/decline", ['answer' => 'Nein.'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/loans/{$loan->id}/extension-requests/{$request->id}/decline", ['answer' => 'Nein.'])->assertSessionHasErrors(['return_at' => 'Über die Anfrage wurde schon entschieden.']);
        $this->actingAs($admin)->post("/loans/{$loan->id}/extension-requests/{$request->id}/accept")->assertSessionHasErrors('return_at');
        Notification::assertSentToTimes($loan, ExtensionDecided::class, 1);
    }

    public function test_an_open_request_is_done_when_the_material_comes_back(): void
    {
        $item = $this->item();
        $loan = $this->issued([$item], now()->subHour(), now()->addDay());
        $this->post(LoanPage::path($loan).'/extension', ['return_at' => now()->addDays(2)->format('Y-m-d\TH:i')]);
        $admin = $this->admin();

        $this->actingAs($admin)->post("/loans/{$loan->id}/return/check", ['code' => $item->number]);
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/complete", ['returner' => 'Erika', 'signatures' => ['returner' => self::SIGNATURE, 'receiver' => self::SIGNATURE]])->assertSessionHasNoErrors();

        $this->assertSame(LoanExtensionRequest::DONE, LoanExtensionRequest::firstOrFail()->status);
        Notification::assertNotSentTo($loan, ExtensionDecided::class);
        $this->assertSame(0, LoanExtensionRequest::where('status', LoanExtensionRequest::OPEN)->count());
        $this->assertTrue($loan->messages()->where('body', 'like', 'Verlängerungsanfrage erledigt%')->exists());
    }
}
