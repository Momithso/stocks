<?php

namespace Modules\Loans\Tests;

use App\Models\Item;
use App\Models\Location;
use App\Models\ReservedNumber;
use App\Models\User;
use App\Support\Demo\DemoGenerator;
use App\Support\Demo\TestSheet;
use App\Support\Settings;
use Illuminate\Support\Facades\Notification;
use Modules\Loans\Actions\IssueLoan;
use Modules\Loans\Actions\StowItem;
use Modules\Loans\Enums\LoanKind;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Models\Contact;
use Modules\Loans\Models\Event;
use Modules\Loans\Models\Loan;
use Modules\Loans\Models\LoanBoxCheck;
use Modules\Loans\Models\LoanExtensionRequest;
use Modules\Loans\Models\LoanItem;
use Modules\Loans\Models\LoanMessage;
use Modules\Loans\Models\LoanNotification;
use Modules\Loans\Support\Availability;

/**
 * Demo data of the loan module and the full test sheet (documentation/DEMO.md).
 */
class DemoTest extends LoansTestCase
{
    public function test_the_generator_creates_loans_in_every_status_and_every_case(): void
    {
        app(DemoGenerator::class)->run();

        $this->assertSame(80, Contact::count());
        $this->assertSame(12, Event::count());
        $this->assertSame(120, Loan::count());
        foreach (LoanStatus::cases() as $status) {
            $this->assertTrue(Loan::where('status', $status)->exists(), "Status {$status->value} fehlt.");
        }
        $this->assertGreaterThan(1, Loan::overdue()->count());
        $this->assertGreaterThan(1, Loan::where('kind', LoanKind::Internal)->where('status', LoanStatus::Issued)->count());
        $this->assertGreaterThan(1, LoanBoxCheck::count());
        $this->assertGreaterThan(1, StowItem::pending()->count());
        $this->assertTrue(LoanItem::stillMissing()->exists(), 'vermisst');
        $this->assertTrue(Contact::whereNull('phone')->exists());
        $this->assertTrue(Contact::whereNull('council_id')->exists() && Contact::whereNotNull('council_id')->exists());
        $this->assertTrue(Loan::where('status', LoanStatus::ClosedWithDefects)->whereNotNull('defects_closed_at')->exists());
        $this->assertTrue(Loan::where('status', LoanStatus::Issued)->whereNotNull('returned_at')->exists() || Loan::where('status', LoanStatus::Issued)->whereHas('loanItems', fn ($query) => $query->whereNotNull('returned_at'))->exists(), 'partial return');

        // a partial issue with a rest that may still leave, and a defect noted at the issue
        $this->assertTrue(Loan::where('status', LoanStatus::Issued)->whereHas('positions', fn ($query) => $query->whereNull('issued_at'))->exists(), 'Teilausgabe');
        $this->assertTrue(LoanItem::whereNotNull('issue_state')->whereNotNull('issued_at')->exists(), 'Mangel bei der Ausgabe');

        // a confirmed loan that was not picked up, and an overdue loan whose rest missed its time
        $this->assertTrue(Loan::notPickedUp()->exists(), 'nicht abgeholt');
        $this->assertTrue(Loan::where('status', LoanStatus::Issued)->get()->contains(fn (Loan $loan) => IssueLoan::restExpired($loan)), 'Rest nicht abgeholt');

        // a draft that collides with a confirmed loan
        $collides = Loan::where('status', LoanStatus::Draft)->get()->contains(fn (Loan $loan) => app(Availability::class)->problems($loan) !== []);
        $this->assertTrue($collides);
    }

    public function test_the_test_sheet_has_24_codes_that_exist_in_the_data(): void
    {
        app(DemoGenerator::class)->run();
        $labels = app(Settings::class)->get('demo.labels');

        $this->assertCount(TestSheet::LABELS, $labels);
        $this->assertCount(TestSheet::LABELS, array_unique(array_column($labels, 'code')));
        foreach ($labels as $label) {
            if ($label['purpose'] === 'Unbekannte Nummer') {
                continue;
            }
            // the borrower page of a loan: its link opens the page
            if (isset($label['url'])) {
                $this->get($label['url'])->assertOk();

                continue;
            }
            $known = Item::where('number', $label['code'])->exists() || Location::where('full_code', $label['code'])->exists() || ReservedNumber::where('number', $label['code'])->exists();
            $this->assertTrue($known, "{$label['code']} ({$label['purpose']}) gibt es nicht.");
        }

        $this->assertStringStartsWith('%PDF', app(TestSheet::class)->pdf());
    }

    public function test_the_generator_fills_the_borrower_pages_without_sending(): void
    {
        Notification::fake();
        app(DemoGenerator::class)->run();

        Notification::assertNothingSent();
        $this->assertGreaterThanOrEqual(3, Loan::has('messages')->count());
        $this->assertSame(1, LoanMessage::where('sender', 'contact')->whereNull('read_by_staff_at')->distinct()->count('loan_id'));
        $this->assertSame(1, LoanExtensionRequest::where('status', 'open')->count());
        $this->assertSame(1, LoanExtensionRequest::where('status', 'accepted')->count());
        $this->assertTrue(LoanNotification::where('channel', 'push')->exists() && LoanNotification::where('channel', 'mail')->exists());
        $this->assertSame(1, Loan::whereNotNull('access_blocked_at')->count());
        $this->assertTrue(LoanItem::whereNotNull('borrower_checked_at')->exists());
        $this->assertNotNull(User::where('email', 'demo2@example.com')->value('notification_preferences'));

        // what would be due right after seeding is marked, the scheduler sends nothing
        $this->assertTrue(LoanNotification::where('recipient', 'Demo, nicht verschickt')->exists());
        $this->artisan('loans:remind')->expectsOutput('0 Erinnerung(en) ausgelöst.');
        Notification::assertNothingSent();
    }
}
