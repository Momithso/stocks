<?php

namespace Modules\Loans\Tests;

use App\Support\Settings;
use Illuminate\Support\Facades\Notification;
use Modules\Loans\Actions\ExtendLoan;
use Modules\Loans\Models\LoanNotification;
use Modules\Loans\Notifications\LoanConfirmed;
use Modules\Loans\Notifications\LoanOverdue;
use Modules\Loans\Notifications\PickupReminder;
use Modules\Loans\Notifications\ReturnReminder;

/**
 * Reminders (loans:remind): each once, overdue in stages, none for loans not picked up, by hand.
 */
class RemindersTest extends LoansTestCase
{
    public function test_pickup_and_return_reminders_go_out_once(): void
    {
        $pickup = $this->confirmed([$this->item()], now()->addHours(10), now()->addDays(2));
        $returning = $this->issued([$this->item()], now()->subDay(), now()->addHours(5));
        $later = $this->confirmed([$this->item()], now()->addDays(5), now()->addDays(6));
        // confirmed days before, so the confirmation is not the last word on the pickup time
        $pickup->forceFill(['confirmed_at' => now()->subDays(3)])->save();

        $this->artisan('loans:remind')->assertSuccessful();
        $this->artisan('loans:remind')->assertSuccessful();

        Notification::assertSentToTimes($pickup, PickupReminder::class, 1);
        Notification::assertSentToTimes($returning, ReturnReminder::class, 1);
        Notification::assertNotSentTo($later, PickupReminder::class);
    }

    public function test_overdue_loans_get_three_stages_and_the_organization_from_the_second(): void
    {
        $issuer = $this->userWithRoles('FSR-Mitglied');
        $loan = $this->issued([$this->item()], now()->subDays(3), now()->subHours(25), $issuer);

        $this->artisan('loans:remind');
        Notification::assertSentTo($loan, LoanOverdue::class, fn (LoanOverdue $notification) => $notification->level === 1);
        Notification::assertNotSentTo($issuer, LoanOverdue::class);

        $this->travel(2)->days();
        $this->artisan('loans:remind');
        $this->artisan('loans:remind');
        Notification::assertSentTo($loan, LoanOverdue::class, fn (LoanOverdue $notification) => $notification->level === 2);
        Notification::assertSentToTimes($issuer, LoanOverdue::class, 1);

        $this->travel(4)->days();
        $this->artisan('loans:remind');
        Notification::assertSentToTimes($loan, LoanOverdue::class, 3);
        $this->assertSame([1, 2, 3], LoanNotification::where('type', 'overdue')->where('channel', 'trigger')->orderBy('stage')->pluck('stage')->all());
    }

    public function test_loans_not_picked_up_get_no_overdue_message(): void
    {
        $loan = $this->confirmed([$this->item()], now()->subDays(5), now()->subDays(4));
        $this->assertTrue($loan->isNotPickedUp());

        $this->artisan('loans:remind');
        Notification::assertNotSentTo($loan, LoanOverdue::class);
        Notification::assertNotSentTo($loan, ReturnReminder::class);
    }

    public function test_a_member_sends_a_message_by_hand_and_the_log_shows_who(): void
    {
        $admin = $this->admin();
        $loan = $this->confirmed([$this->item()], now()->addHours(5), now()->addDay());
        $loan->forceFill(['confirmed_at' => now()->subDays(3)])->save();

        $this->actingAs($admin)->post("/loans/{$loan->id}/remind", ['type' => 'confirmed'])->assertSessionHasNoErrors();
        Notification::assertSentTo($loan, LoanConfirmed::class, fn (LoanConfirmed $notification) => $notification->sentBy === $admin->id);
        $this->post("/loans/{$loan->id}/remind", ['type' => 'nonsense'])->assertSessionHasErrors('type');

        $this->get("/loans/{$loan->id}")->assertInertia(fn ($page) => $page->where('borrowerPage.log.0.sent_by', $admin->name)->where('borrowerPage.log.0.channel', 'trigger'));

        // the pickup reminder was not sent by hand, so it still comes automatically
        $this->artisan('loans:remind');
        Notification::assertSentTo($loan, PickupReminder::class);
    }

    public function test_a_setting_of_zero_switches_a_reminder_off(): void
    {
        app(Settings::class)->set(['loans.remind_pickup_hours' => 0]);
        $loan = $this->confirmed([$this->item()], now()->addHours(2), now()->addDay());

        $this->artisan('loans:remind');
        Notification::assertNotSentTo($loan, PickupReminder::class);
    }

    public function test_a_loan_confirmed_within_the_reminder_time_gets_no_pickup_reminder(): void
    {
        $loan = $this->confirmed([$this->item()], now()->addHours(5), now()->addDay());

        $this->artisan('loans:remind');
        Notification::assertNotSentTo($loan, PickupReminder::class);
    }

    public function test_an_extension_brings_the_return_reminder_and_the_overdue_stages_again(): void
    {
        $loan = $this->issued([$this->item()], now()->subDays(3), now()->addHours(5));
        $this->artisan('loans:remind');
        Notification::assertSentToTimes($loan, ReturnReminder::class, 1);

        // overdue once, then extended: the new time gets its reminder and its stages again
        $this->travel(1)->days();
        $this->travel(6)->hours();
        $this->artisan('loans:remind');
        Notification::assertSentToTimes($loan, LoanOverdue::class, 1);

        app(ExtendLoan::class)->handle($loan->refresh(), now()->addHours(10)->startOfMinute());
        $this->artisan('loans:remind');
        Notification::assertSentToTimes($loan, ReturnReminder::class, 2);

        $this->travel(1)->days();
        $this->travel(11)->hours();
        $this->artisan('loans:remind');
        $this->artisan('loans:remind');
        Notification::assertSentToTimes($loan, LoanOverdue::class, 2);
    }

    public function test_a_reminder_by_hand_stands_for_the_automatic_one_of_the_same_time(): void
    {
        $loan = $this->issued([$this->item()], now()->subDay(), now()->addHours(5));

        $this->actingAs($this->admin())->post("/loans/{$loan->id}/remind", ['type' => 'return_reminder'])->assertSessionHasNoErrors();
        $this->artisan('loans:remind');
        Notification::assertSentToTimes($loan, ReturnReminder::class, 1);
    }
}
