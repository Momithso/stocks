<?php

namespace Modules\Loans\Tests;

use App\Support\Settings;
use Modules\Loans\Actions\CancelLoan;
use Modules\Loans\Actions\ConfirmLoan;
use Modules\Loans\Enums\LoanKind;
use Modules\Loans\Models\Loan;
use Modules\Loans\Notifications\LoanConfirmed;
use Modules\Loans\Support\LoanPage;
use Modules\Loans\Support\Reminders;

/**
 * Borrower page: access with the link, expiry, blocking, new link, what it shows.
 */
class LoanPageTest extends LoansTestCase
{
    private function path(Loan $loan, string $suffix = ''): string
    {
        return LoanPage::path($loan).$suffix;
    }

    public function test_the_right_link_opens_the_page_and_a_wrong_one_answers_404(): void
    {
        $loan = $this->confirmed([$this->item()]);

        $this->get($this->path($loan))->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertInertia(fn ($page) => $page->component('Loans::Page/Show', false)->where('loan.number', $loan->number)->where('loan.status', 'confirmed'));

        $this->get('/v/'.$loan->id.'/'.str_repeat('a', 43))->assertNotFound();
        $this->get('/v/999999/'.LoanPage::token($loan))->assertNotFound();
        // the key of one loan does not open another
        $other = $this->confirmed([$this->item()]);
        $this->get('/v/'.$other->id.'/'.LoanPage::token($loan))->assertNotFound();
    }

    public function test_drafts_and_internal_loans_have_no_page(): void
    {
        $draft = $this->draft([$this->item()]);
        $this->get($this->path($draft))->assertNotFound();

        $internal = $this->draft([$this->item()], data: ['kind' => LoanKind::Internal->value, 'responsible_user_id' => $this->admin()->id]);
        app(ConfirmLoan::class)->handle($internal);
        $this->assertFalse(LoanPage::exists($internal->refresh()));
        $this->get($this->path($internal))->assertNotFound();
    }

    public function test_the_link_holds_during_a_long_overdue_loan_and_expires_after_the_close(): void
    {
        $loan = $this->issued([$this->item()], now()->subDays(60), now()->subDays(40));
        $this->assertTrue($loan->isOverdue());
        $this->get($this->path($loan))->assertOk()->assertInertia(fn ($page) => $page->where('loan.status', 'overdue'));

        $cancelled = $this->confirmed([$this->item()]);
        app(CancelLoan::class)->handle($cancelled, 'abgesagt');
        $this->get($this->path($cancelled->refresh()))->assertOk();
        $this->travel(31)->days();
        $this->get($this->path($cancelled))->assertNotFound();

        app(Settings::class)->set(['loans.page_days_after_close' => 60]);
        $this->get($this->path($cancelled))->assertOk();
    }

    public function test_blocking_and_a_new_link_end_the_old_one_and_its_push(): void
    {
        $loan = $this->confirmed([$this->item()]);
        $old = $this->path($loan);
        $loan->pushSubscriptions()->create(['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc']);
        $admin = $this->admin();

        $this->actingAs($admin)->post("/loans/{$loan->id}/page/block", ['blocked' => true])->assertSessionHasNoErrors();
        $this->get($old)->assertNotFound();
        $this->assertSame(0, $loan->pushSubscriptions()->count());

        $this->post("/loans/{$loan->id}/page/block", ['blocked' => false]);
        $this->get($old)->assertOk();

        $this->post("/loans/{$loan->id}/page/reissue")->assertSessionHasNoErrors();
        $this->get($old)->assertNotFound();
        $this->get($this->path($loan->refresh()))->assertOk();
    }

    public function test_the_page_shows_contacts_of_both_sides_and_only_its_documents(): void
    {
        app(Settings::class)->set(['organization.phone' => '0241 600 1234']);
        $issuer = $this->admin();
        $loan = $this->issued([$this->item()], issuer: $issuer);
        $other = $this->issued([$this->item()]);

        $this->get($this->path($loan))->assertInertia(fn ($page) => $page
            ->where('contacts.responsible.phone', '0241 123')
            ->where('contacts.responsible.address', 'Eupener Str. 70, 52066 Aachen')
            ->where('contacts.organization.phone', '0241 600 1234')
            ->where('contacts.members', fn ($members) => collect($members)->contains($issuer->name))
            ->has('documents', 2)
            ->where('loan.pack_pin', $loan->pack_pin));

        $document = $loan->documents()->first();
        $this->get($this->path($loan, "/documents/{$document->id}"))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get($this->path($loan, '/documents/'.$other->documents()->first()->id))->assertNotFound();
    }

    public function test_the_manifest_makes_the_page_an_app_of_its_own(): void
    {
        $loan = $this->confirmed([$this->item()]);

        $this->get($this->path($loan, '/manifest.webmanifest'))->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertJson(['name' => "Ausleihe {$loan->number}", 'start_url' => $this->path($loan), 'scope' => $this->path($loan), 'display' => 'standalone']);
    }

    public function test_the_borrower_changes_the_pack_pin(): void
    {
        $loan = $this->confirmed([$this->item()]);

        $this->post($this->path($loan, '/pin'), ['pin' => '12ab'])->assertSessionHasErrors('pin');
        $this->post($this->path($loan, '/pin'), ['pin' => '424242'])->assertSessionHasNoErrors();
        $this->assertSame('424242', $loan->refresh()->pack_pin);
    }

    public function test_the_code_of_the_page_is_in_the_pdf_but_not_in_the_signed_content(): void
    {
        $loan = $this->issued([$this->item()]);
        $document = $loan->documents()->first();
        $hash = $document->hash;

        $html = view('loans::pdf.list', ['doc' => $document->snapshot, 'signatures' => [], 'hash' => $hash, 'logo' => null, 'qr' => LoanPage::qrDataUri($loan)])->render();
        $this->assertStringContainsString('data:image/svg+xml;base64,', $html);
        $this->assertStringContainsString('Verleihseite', $html);
        $this->assertTrue($document->refresh()->isUnchanged());
        $this->assertSame($hash, $document->hash);
    }

    public function test_the_daily_clean_up_removes_push_of_expired_pages(): void
    {
        $open = $this->confirmed([$this->item()]);
        $expired = $this->confirmed([$this->item()]);
        foreach ([$open, $expired] as $index => $loan) {
            $loan->pushSubscriptions()->create(['endpoint' => "https://fcm.googleapis.com/fcm/send/{$index}"]);
        }
        app(CancelLoan::class)->handle($expired, 'abgesagt');
        $this->travel(31)->days();

        $this->assertSame(1, app(Reminders::class)->cleanUp(app(LoanPage::class)));
        $this->assertSame(1, $open->pushSubscriptions()->count());
        $this->assertSame(0, $expired->pushSubscriptions()->count());
    }

    public function test_a_poll_does_not_become_the_page_a_form_returns_to(): void
    {
        $loan = $this->confirmed([$this->item()]);
        $page = LoanPage::url($loan);

        $this->get($page)->assertOk();
        // the live update asks for json, like fetch() of the page does
        $this->get(LoanPage::path($loan).'/poll', ['Accept' => 'application/json'])->assertOk();
        $this->assertSame($page, session()->previousUrl());

        $this->put(LoanPage::path($loan).'/notifications', ['events' => ['loans.chat' => ['mail' => false]]])->assertRedirect($page);
    }

    public function test_defects_show_only_once_the_return_is_booked(): void
    {
        $item = $this->item();
        $loan = $this->issued([$item, $this->item()], now()->subHour(), now()->addDay());
        $row = $loan->positions()->where('item_id', $item->id)->firstOrFail();
        $admin = $this->admin();

        $this->actingAs($admin)->post("/loans/{$loan->id}/return/rows/{$row->id}/defect", ['state' => 'dirty', 'note' => 'Kleber'])->assertSessionHasNoErrors();
        auth()->logout();
        $this->get(LoanPage::path($loan))->assertInertia(fn ($page) => $page->where('defects', []));

        $this->actingAs($admin)->post("/loans/{$loan->id}/return/complete", ['returner' => 'Erika', 'signatures' => ['returner' => self::SIGNATURE, 'receiver' => self::SIGNATURE]])->assertSessionHasNoErrors();
        auth()->logout();
        $this->get(LoanPage::path($loan))->assertInertia(fn ($page) => $page->where('defects.0.note', 'Kleber'));
    }

    public function test_a_missed_pickup_is_named_on_the_page(): void
    {
        $loan = $this->confirmed([$this->item()], now()->subHours(3), now()->addDay());

        $this->get(LoanPage::path($loan))->assertInertia(fn ($page) => $page->where('loan.sentence', fn ($sentence) => str_starts_with($sentence, 'Die Abholung war am')));
    }

    public function test_the_limit_counts_per_loan_so_borrowers_behind_one_address_do_not_share_it(): void
    {
        $first = $this->confirmed([$this->item()]);
        $second = $this->confirmed([$this->item()]);

        for ($try = 0; $try < 60; $try++) {
            $this->getJson(LoanPage::path($first).'/poll')->assertOk();
        }
        $this->getJson(LoanPage::path($first).'/poll')->assertStatus(429);
        $this->getJson(LoanPage::path($second).'/poll')->assertOk();
    }

    public function test_mails_greet_both_contacts(): void
    {
        $responsible = $this->contact(['name' => 'Erika Muster']);
        $borrower = $this->contact(['name' => 'Fachschaft 5']);
        $loan = $this->draft([$this->item()], data: ['responsible_contact_id' => $responsible->id, 'borrower_contact_id' => $borrower->id]);

        $this->assertSame('Hallo Erika Muster, hallo Fachschaft 5!', (new LoanConfirmed($loan))->message($loan)->greeting);
    }
}
