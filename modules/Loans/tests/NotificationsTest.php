<?php

namespace Modules\Loans\Tests;

use App\Models\ItemCondition;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Modules\Loans\Actions\CancelLoan;
use Modules\Loans\Actions\ReturnLoan;
use Modules\Loans\Enums\ReturnState;
use Modules\Loans\Models\LoanNotification;
use Modules\Loans\Notifications\DefectReported;
use Modules\Loans\Notifications\LoanCancelled;
use Modules\Loans\Notifications\LoanConfirmed;
use Modules\Loans\Notifications\LoanIssued;
use Modules\Loans\Notifications\LoanReturned;
use Modules\Loans\Notifications\PickupReminder;
use Modules\Loans\Support\LoanPage;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * Notifications of the borrower side: receivers, attachments only by mail, required mails, the
 * settings of the borrower page, push only with keys, the log.
 */
class NotificationsTest extends LoansTestCase
{
    public function test_confirming_sends_link_and_pin_unless_left_out(): void
    {
        $admin = $this->admin();
        $loan = $this->draft([$this->item()]);
        $this->actingAs($admin)->post("/loans/{$loan->id}/confirm")->assertSessionHasNoErrors();

        Notification::assertSentTo($loan, LoanConfirmed::class, function (LoanConfirmed $notification, array $channels) use ($loan) {
            $mail = $notification->toMail($loan->refresh());

            return $channels === ['mail'] && str_contains(implode(' ', $mail->introLines), $loan->pack_pin)
                && $mail->actionUrl === LoanPage::url($loan) && count($mail->rawAttachments) === 1;
        });

        $quiet = $this->draft([$this->item()]);
        $this->post("/loans/{$quiet->id}/confirm", ['notify' => false])->assertSessionHasNoErrors();
        Notification::assertNotSentTo($quiet, LoanConfirmed::class);
    }

    public function test_the_issue_sends_contract_and_list_and_the_return_its_receipt(): void
    {
        $loan = $this->issued([$item = $this->item()]);
        Notification::assertSentTo($loan, LoanIssued::class, fn (LoanIssued $notification) => count($notification->message($loan)->attachments) === 2);
        // push carries no attachment and no pin
        $this->assertSame([], array_diff(array_keys($notification = (new LoanIssued($loan))->toWebPush($loan)->toArray()), ['title', 'body', 'icon', 'tag', 'data']));

        $return = app(ReturnLoan::class);
        $admin = $this->admin();
        $return->defect($admin, $loan, false, $loan->positions()->first(), ReturnState::Damaged, 'Kratzer', ItemCondition::find($this->conditionId('gebraucht')), []);
        $return->complete($admin, $loan, ['returner' => self::SIGNATURE, 'receiver' => self::SIGNATURE], false, 'Erika Muster', null);

        Notification::assertSentTo($loan, LoanReturned::class, fn (LoanReturned $notification) => count($notification->message($loan)->attachments) === 1);
        Notification::assertSentTo($loan, DefectReported::class);
        $this->assertStringContainsString('Mangel gemeldet', $loan->messages()->first()->body);
    }

    public function test_only_confirmed_loans_tell_about_a_cancellation(): void
    {
        $confirmed = $this->confirmed([$this->item()]);
        $draft = $this->draft([$this->item()]);
        app(CancelLoan::class)->handle($confirmed, 'Feier fällt aus');
        app(CancelLoan::class)->handle($draft, 'Doppelt');

        Notification::assertSentTo($confirmed, LoanCancelled::class);
        Notification::assertNotSentTo($draft, LoanCancelled::class);
    }

    public function test_the_page_settings_switch_messages_off_but_never_the_required_mails(): void
    {
        $loan = $this->confirmed([$this->item()]);
        $this->put(LoanPage::path($loan).'/notifications', ['events' => [
            'loans.pickup_reminder' => ['mail' => false, 'push' => false],
            'loans.confirmed' => ['mail' => false],
        ]])->assertSessionHasNoErrors();

        $loan->refresh();
        $this->assertSame(['loans.pickup_reminder' => ['mail' => false, 'push' => false]], $loan->notification_preferences);
        $this->assertSame([], (new PickupReminder($loan))->via($loan));
        $this->assertSame(['mail'], (new LoanConfirmed($loan))->via($loan));
    }

    public function test_push_goes_only_with_keys_and_a_device(): void
    {
        $loan = $this->confirmed([$this->item()]);
        $loan->pushSubscriptions()->create(['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc', 'public_key' => 'k', 'auth_token' => 't']);
        $this->assertSame(['mail'], (new PickupReminder($loan))->via($loan));

        config(['webpush.vapid.public_key' => 'public', 'webpush.vapid.private_key' => 'private']);
        $this->assertSame(['mail', WebPushChannel::class], (new PickupReminder($loan))->via($loan));
        $this->get(LoanPage::path($loan))->assertInertia(fn ($page) => $page->where('notifications.push_key', 'public')->where('notifications.push_devices', 1));
    }

    public function test_the_page_stores_push_only_for_known_services(): void
    {
        config(['webpush.vapid.public_key' => 'public', 'webpush.vapid.private_key' => 'private']);
        $loan = $this->confirmed([$this->item()]);
        $keys = ['keys' => ['p256dh' => 'key', 'auth' => 'token']];

        $this->postJson(LoanPage::path($loan).'/push', ['endpoint' => 'https://evil.example.com/push', ...$keys])->assertUnprocessable();
        $this->postJson(LoanPage::path($loan).'/push', ['endpoint' => 'https://web.push.apple.com/abc', ...$keys], ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)'])
            ->assertOk()->assertJsonPath('device', 'iPhone');
        $this->assertSame(1, $loan->pushSubscriptions()->count());

        $this->deleteJson(LoanPage::path($loan).'/push', ['endpoint' => 'https://web.push.apple.com/abc'])->assertOk();
        $this->assertSame(0, $loan->pushSubscriptions()->count());
    }

    public function test_the_log_has_one_row_per_receiver_and_channel(): void
    {
        // real sending into a fake mailer, so the log listener runs
        Notification::swap(new ChannelManager($this->app));
        Mail::fake();
        $borrower = $this->contact(['name' => 'Fachschaft Elektro', 'email' => 'elektro@example.com']);
        $loan = $this->draft([$this->item()], data: ['borrower_contact_id' => $borrower->id]);

        $this->actingAs($this->admin())->post("/loans/{$loan->id}/confirm")->assertSessionHasNoErrors();

        $rows = LoanNotification::where('loan_id', $loan->id)->where('type', 'confirmed')->get();
        $this->assertSame(['mail'], $rows->pluck('channel')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(['Erika Muster <erika@example.com>', 'Fachschaft Elektro <elektro@example.com>'], $rows->pluck('recipient')->all());
        $this->get("/loans/{$loan->id}")->assertInertia(fn ($page) => $page->has('borrowerPage.log', 2)->where('borrowerPage.log.0.label', 'Buchung bestätigt'));
    }
}
