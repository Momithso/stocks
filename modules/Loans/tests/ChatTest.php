<?php

namespace Modules\Loans\Tests;

use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\Notification;
use Modules\Loans\Models\LoanMessage;
use Modules\Loans\Notifications\NewChatMessage;
use Modules\Loans\Support\LoanPage;

/**
 * Chat between the borrower page and the organization, with its notifications.
 */
class ChatTest extends LoansTestCase
{
    public function test_messages_go_both_ways_and_the_read_state_is_kept(): void
    {
        $creator = $this->userWithRoles('FSR-Mitglied');
        $loan = $this->confirmed([$this->item()]);
        $loan->forceFill(['created_by' => $creator->id])->save();

        $this->postJson(LoanPage::path($loan).'/messages', ['body' => 'Können wir 30 Minuten später kommen?'])->assertOk()->assertJsonPath('mine', true);
        $this->actingAs($creator)->get('/loans?status=unread')->assertOk();
        $this->assertSame(1, LoanMessage::whereNull('read_by_staff_at')->count());

        // the member reads (poll with read) and answers
        $this->getJson("/loans/{$loan->id}/messages?read=1")->assertJsonPath('messages.0.mine', false)->assertJsonPath('messages.0.author', 'Entleiher (Erika Muster)');
        $this->assertSame(0, LoanMessage::whereNull('read_by_staff_at')->count());
        $this->postJson("/loans/{$loan->id}/messages", ['body' => 'Klar, kein Problem.'])->assertOk()->assertJsonPath('mine', true);

        // the borrower sees the answer as unread until the chat is opened
        $this->getJson(LoanPage::path($loan).'/poll?after=0')->assertJsonPath('loan.unread', 1)->assertJsonCount(2, 'messages');
        $this->getJson(LoanPage::path($loan).'/poll?read=1')->assertJsonPath('loan.unread', 0);
    }

    public function test_the_involved_members_get_the_message_by_their_settings(): void
    {
        $creator = $this->userWithRoles('FSR-Mitglied');
        $issuer = $this->userWithRoles('FSR-Mitglied');
        $outsider = $this->userWithRoles('FSR-Mitglied');
        $viewer = $this->userWithRoles('Freies Mitglied');
        $loan = $this->issued([$this->item()], issuer: $issuer);
        $loan->forceFill(['created_by' => $creator->id])->save();
        $viewer->forceFill(['notification_preferences' => null])->save();
        // mails are off until a member switches them on; the issuer did not
        $creator->forceFill(['notification_preferences' => ['loans.chat_staff' => ['mail' => true]]])->save();

        $this->postJson(LoanPage::path($loan).'/messages', ['body' => 'Hallo'])->assertOk();

        Notification::assertSentTo($creator, NewChatMessage::class, fn ($notification, array $channels) => $channels === ['mail']);
        // mail not switched on and no device with push: no channel, nothing goes out
        $this->assertSame([], (new NewChatMessage($loan, $loan->messages()->first(), true))->via($issuer));
        Notification::assertNotSentTo([$issuer, $outsider, $viewer], NewChatMessage::class);
    }

    public function test_all_switched_off_means_nothing_and_the_contact_address_only_without_anybody(): void
    {
        app(Settings::class)->set(['legal.contact_email' => 'fsr@example.com']);
        $creator = $this->userWithRoles('FSR-Mitglied');
        $creator->forceFill(['notification_preferences' => ['loans.chat_staff' => ['mail' => false, 'push' => false]]])->save();
        $loan = $this->confirmed([$this->item()]);
        $loan->forceFill(['created_by' => $creator->id])->save();

        $this->postJson(LoanPage::path($loan).'/messages', ['body' => 'Hallo'])->assertOk();
        Notification::assertNotSentTo($creator, NewChatMessage::class);
        Notification::assertSentOnDemandTimes(NewChatMessage::class, 0);

        // nobody involved is active any more: the contact address of the organization
        $creator->forceFill(['is_disabled' => true])->save();
        $orphan = $this->confirmed([$this->item()]);
        $orphan->forceFill(['created_by' => $creator->id])->save();
        $this->postJson(LoanPage::path($orphan).'/messages', ['body' => 'Hallo?'])->assertOk();
        Notification::assertSentOnDemand(NewChatMessage::class, fn ($notification, array $channels, object $notifiable) => $notifiable->routes['mail'] === 'fsr@example.com');
    }

    public function test_messages_are_bundled_while_nothing_was_read(): void
    {
        $creator = $this->userWithRoles('FSR-Mitglied');
        $creator->forceFill(['notification_preferences' => ['loans.chat_staff' => ['mail' => true]]])->save();
        $loan = $this->confirmed([$this->item()]);
        $loan->forceFill(['created_by' => $creator->id])->save();

        $this->postJson(LoanPage::path($loan).'/messages', ['body' => 'Eins'])->assertOk();
        $this->postJson(LoanPage::path($loan).'/messages', ['body' => 'Zwei'])->assertOk();
        Notification::assertSentToTimes($creator, NewChatMessage::class, 1);

        // read: the next one notifies again
        $this->actingAs($creator)->getJson("/loans/{$loan->id}/messages?read=1")->assertOk();
        $this->postJson(LoanPage::path($loan).'/messages', ['body' => 'Drei'])->assertOk();
        Notification::assertSentToTimes($creator, NewChatMessage::class, 2);

        // or a quarter of an hour later
        $this->travel(16)->minutes();
        $this->postJson(LoanPage::path($loan).'/messages', ['body' => 'Vier'])->assertOk();
        Notification::assertSentToTimes($creator, NewChatMessage::class, 3);
    }

    public function test_text_stays_text_and_only_members_who_manage_loans_see_the_chat(): void
    {
        $loan = $this->confirmed([$this->item()]);
        $this->postJson(LoanPage::path($loan).'/messages', ['body' => '<script>alert(1)</script>'])->assertOk()->assertJsonPath('body', '<script>alert(1)</script>');
        $this->postJson(LoanPage::path($loan).'/messages', ['body' => str_repeat('a', 2001)])->assertUnprocessable();

        $this->actingAs($this->userWithRoles('Freies Mitglied'))->getJson("/loans/{$loan->id}/messages")->assertForbidden();
        $this->actingAs($this->userWithRoles('Freies Mitglied'))->get("/loans/{$loan->id}")->assertInertia(fn ($page) => $page->where('borrowerPage', null));
        $this->actingAs($this->admin())->get("/loans/{$loan->id}")->assertInertia(fn ($page) => $page->has('borrowerPage.messages', 1));
    }

    public function test_the_menu_counts_loans_with_unread_messages(): void
    {
        $member = $this->userWithRoles('FSR-Mitglied');
        $loan = $this->confirmed([$this->item()]);
        $this->postJson(LoanPage::path($loan).'/messages', ['body' => 'Hallo'])->assertOk();

        $badge = fn (User $user) => collect($this->actingAs($user)->get('/dashboard')->viewData('page')['props']['navigation'])
            ->flatMap(fn ($section) => $section['entries'])->firstWhere('title', 'Buchungen')['badge'] ?? null;
        $this->assertSame('1 Nachricht', $badge($member)['text']);
        $this->getJson("/loans/{$loan->id}/messages?read=1");
        $this->assertNull($badge($member));
    }

    public function test_only_the_own_messages_are_mine_for_a_member(): void
    {
        $loan = $this->confirmed([$this->item()]);
        [$anna, $ben] = [$this->admin(), $this->admin()];

        $this->actingAs($anna)->postJson("/loans/{$loan->id}/messages", ['body' => 'Von Anna'])->assertOk()->assertJsonPath('mine', true);
        $this->actingAs($ben)->getJson("/loans/{$loan->id}/messages")->assertJsonPath('messages.0.mine', false);
        $this->actingAs($anna)->getJson("/loans/{$loan->id}/messages")->assertJsonPath('messages.0.mine', true);
    }

    public function test_a_member_gets_no_mail_until_switching_it_on(): void
    {
        app(Settings::class)->set(['legal.contact_email' => 'fsr@example.com']);
        $creator = $this->userWithRoles('FSR-Mitglied');
        $loan = $this->confirmed([$this->item()]);
        $loan->forceFill(['created_by' => $creator->id])->save();

        $this->postJson(LoanPage::path($loan).'/messages', ['body' => 'Hallo'])->assertOk();
        Notification::assertNotSentTo($creator, NewChatMessage::class);
        // nobody gets it instead: the contact address only steps in when nobody is involved
        Notification::assertSentOnDemandTimes(NewChatMessage::class, 0);

        $this->actingAs($creator)->put('/profile/notifications', ['events' => ['loans.chat_staff' => ['mail' => true]]])->assertSessionHasNoErrors();
        $this->getJson("/loans/{$loan->id}/messages?read=1")->assertOk();
        auth()->logout();
        $this->postJson(LoanPage::path($loan).'/messages', ['body' => 'Hallo?'])->assertOk();
        Notification::assertSentTo($creator, NewChatMessage::class, fn ($notification, array $channels) => $channels === ['mail']);
    }
}
