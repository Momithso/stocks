<?php

namespace Modules\Loans\Support;

use App\Models\User;
use App\Support\Notifications\NotificationEvents;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Loans\Models\Loan;
use Modules\Loans\Models\LoanMessage;
use Modules\Loans\Notifications\LoanEventNotification;
use Modules\Loans\Notifications\NewChatMessage;

/**
 * Who gets the notifications of a loan (documentation/LOANS.md):
 *
 * - the borrower side: the loan itself (mail to the contacts, push to the borrower page), only for
 *   loans with a borrower page;
 * - the organization: the members involved (created the loan, issued it, answered in the chat)
 *   whose account is active and still has "loans.manage", by their own settings. Only if there is
 *   nobody involved, the mail goes to "legal.contact_email". Switched off means switched off.
 *
 * Everything is sent after the database transaction, so a rolled back step notifies nobody.
 */
class LoanNotifier
{
    /**
     * Minutes between two chat notifications to the same side while nothing was read.
     */
    public const CHAT_PAUSE_MINUTES = 15;

    /**
     * Cache key of the number of loans with unread messages of borrowers (badge of the menu).
     */
    private const UNREAD_KEY = 'loans.unread-messages';

    public function __construct(private readonly Settings $settings) {}

    /**
     * Notify the borrower side.
     */
    public function toContact(Loan $loan, LoanEventNotification $notification): void
    {
        if (! LoanPage::exists($loan) || NotificationEvents::isMuted()) {
            return;
        }
        DB::afterCommit(fn () => $loan->notify($notification));
    }

    /**
     * Notify the members involved, or the contact address of the organization if there are none.
     */
    public function toStaff(Loan $loan, LoanEventNotification $notification): void
    {
        if (NotificationEvents::isMuted()) {
            return;
        }
        DB::afterCommit(function () use ($loan, $notification) {
            $staff = $this->staff($loan);
            if ($staff->isNotEmpty()) {
                Notification::send($staff, $notification);
            } elseif ($address = $this->settings->get('legal.contact_email')) {
                Notification::route('mail', $address)->notify($notification);
            }
        });
    }

    /**
     * Members involved in a loan: who created it, who issued it, who answered in the chat; with an
     * active account and "loans.manage".
     *
     * @return Collection<int, User>
     */
    public function staff(Loan $loan): Collection
    {
        $ids = collect([$loan->created_by, $loan->issuer_id])
            ->merge($loan->messages()->where('sender', LoanMessage::USER)->whereNotNull('user_id')->pluck('user_id'))
            ->filter()->unique()->values();

        return User::whereIn('id', $ids)->where('is_disabled', false)->orderBy('id')->get()
            ->filter(fn (User $user) => $user->can('loans.manage'))->values();
    }

    /**
     * Notify the other side of a new chat message: at most once per pause and loan while the other
     * side has not read anything (markRead() ends the pause).
     */
    public function chat(Loan $loan, LoanMessage $message): void
    {
        $toStaff = $message->sender === LoanMessage::CONTACT;
        if (! Cache::add(self::chatKey($loan, $toStaff), true, now()->addMinutes(self::CHAT_PAUSE_MINUTES))) {
            return;
        }

        $notification = new NewChatMessage($loan, $message, $toStaff);
        $toStaff ? $this->toStaff($loan, $notification) : $this->toContact($loan, $notification);
    }

    /**
     * A side read the chat: the next message to it notifies at once again.
     */
    public static function markRead(Loan $loan, bool $staff): void
    {
        Cache::forget(self::chatKey($loan, $staff));
        self::clearUnread();
    }

    /**
     * Number of loans with messages of borrowers nobody read yet; cached until a message is sent
     * or read (clearUnread()), so the menu does not query on every page.
     */
    public static function unreadCount(): int
    {
        return Cache::rememberForever(self::UNREAD_KEY, fn () => LoanMessage::where('sender', LoanMessage::CONTACT)->whereNull('read_by_staff_at')->distinct()->count('loan_id'));
    }

    /**
     * Forget the cached number of unread messages.
     */
    public static function clearUnread(): void
    {
        Cache::forget(self::UNREAD_KEY);
    }

    private static function chatKey(Loan $loan, bool $toStaff): string
    {
        return "loans.chat-notified.{$loan->id}.".($toStaff ? 'staff' : 'contact');
    }
}
