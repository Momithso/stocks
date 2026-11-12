<?php

namespace Modules\Loans\Notifications;

use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\StocksNotification;
use App\Support\Notifications\NotificationMessage;
use App\Support\Settings;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Minishlink\WebPush\MessageSentReport;
use Modules\Loans\Models\Loan;
use Modules\Loans\Support\LoanPage;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * Base of the notifications of a loan (documentation/LOANS.md, table of messages). The receiver is
 * the loan itself for the borrower side (mail to the contacts, push to the borrower page) or an
 * account for the organization. Every delivery writes one row per receiver and channel to
 * loan_notifications.
 */
abstract class LoanEventNotification extends StocksNotification
{
    /**
     * @param  int|null  $sentBy  member who sent it by hand, null: automatic
     */
    public function __construct(public readonly Loan $loan, public readonly ?int $sentBy = null) {}

    /**
     * Type in the log and in the settings of the texts, e.g. "confirmed".
     */
    abstract public function type(): string;

    /**
     * Stage of a reminder (overdue), null otherwise.
     */
    public function stage(): ?int
    {
        return null;
    }

    /**
     * Time a reminder is about (pickup or return), null for other messages. A new time gets its
     * reminders again (Reminders::once()).
     */
    public function dueAt(): ?Carbon
    {
        return null;
    }

    public function event(): string
    {
        return 'loans.'.$this->type();
    }

    /**
     * Write the log: one row per receiver and channel.
     */
    public function sent(object $notifiable, string $channel, mixed $response): void
    {
        $recipients = match (true) {
            $channel === WebPushChannel::class => $this->pushRecipients($notifiable, is_array($response) ? $response : []),
            $notifiable instanceof Loan => array_map(fn (string $name, string $email) => "{$name} <{$email}>", $notifiable->routeNotificationForMail(), array_keys($notifiable->routeNotificationForMail())),
            $notifiable instanceof User => ["{$notifiable->name} <{$notifiable->email}>"],
            $notifiable instanceof AnonymousNotifiable => array_map(fn ($email) => (string) $email, (array) ($notifiable->routes['mail'] ?? [])),
            default => [],
        };

        foreach ($recipients as $recipient) {
            $this->loan->notificationLog()->create([
                'type' => $this->type(),
                'stage' => $this->stage(),
                'due_at' => $this->dueAt(),
                'channel' => $channel === WebPushChannel::class ? 'push' : 'mail',
                'recipient' => mb_substr($recipient, 0, 190),
                'sent_by' => $this->sentBy,
                'sent_at' => now(),
            ]);
        }
    }

    /**
     * Devices that accepted the push, e.g. "Push: iPhone (Borrower page)".
     *
     * @param  array<int, mixed>  $reports
     * @return list<string>
     */
    private function pushRecipients(object $notifiable, array $reports): array
    {
        $endpoints = collect($reports)->filter(fn ($report) => $report instanceof MessageSentReport && $report->isSuccess())->map(fn (MessageSentReport $report) => $report->getEndpoint());
        $owner = $notifiable instanceof User ? $notifiable->name : 'Verleihseite';

        return PushSubscription::whereIn('endpoint', $endpoints->all())->get()
            ->map(fn (PushSubscription $subscription) => "{$owner}: ".($subscription->device ?: 'Gerät'))->all();
    }

    /**
     * Message for the borrower side: greeting, lines, the extra text of the settings and the link
     * of the borrower page.
     *
     * @param  list<string>  $lines
     */
    protected function forContact(string $title, array $lines, string $short, ?string $tab = null, string $action = 'Verleihseite öffnen'): NotificationMessage
    {
        $this->loan->loadMissing('responsible');
        $extra = $this->extraText();

        return new NotificationMessage(
            title: "{$this->loan->number} · {$title}",
            lines: [...$lines, ...($extra ? [$extra] : []), 'Alles zu deiner Ausleihe, die Dokumente und den Chat mit uns findest du auf der Verleihseite.'],
            url: LoanPage::url($this->loan, $tab),
            action: $action,
            short: $short,
            greeting: $this->greeting(),
        );
    }

    /**
     * Greeting of the borrower side: everyone the mail goes to (responsible person and a borrower
     * that is another contact), e.g. "Hallo Erika Muster, hallo Fachschaft 5!".
     */
    private function greeting(): string
    {
        $this->loan->loadMissing('borrower');
        $names = array_values(array_unique(array_filter([
            $this->loan->responsible?->name,
            $this->loan->borrower_contact_id !== $this->loan->responsible_contact_id && filled($this->loan->borrower?->email) ? $this->loan->borrower->name : null,
        ])));

        return $names ? 'Hallo '.implode(', hallo ', $names).'!' : 'Hallo!';
    }

    /**
     * Message for a member: the link of the loan page in Stocks.
     *
     * @param  list<string>  $lines
     */
    protected function forStaff(string $title, array $lines, string $short, ?string $anchor = null): NotificationMessage
    {
        return new NotificationMessage(
            title: "{$this->loan->number} · {$title}",
            lines: $lines,
            url: route('loans.show', $this->loan).($anchor ? "#{$anchor}" : ''),
            action: 'Buchung öffnen',
            short: $short,
        );
    }

    /**
     * Name of the loan in sentences: "deine Ausleihe B-2026-0042 (Erstiwoche)".
     */
    protected function name(): string
    {
        return $this->loan->number.($this->loan->purpose ? " ({$this->loan->purpose})" : '');
    }

    /**
     * Date and time as "12.11.2026, 16:00 Uhr".
     */
    protected static function time(\DateTimeInterface $time): string
    {
        return $time->format('d.m.Y, H:i').' Uhr';
    }

    /**
     * Place of the pickup and return: the setting, else the address of the organization.
     */
    protected static function place(): ?string
    {
        $settings = app(Settings::class);
        $place = trim((string) ($settings->get('loans.pickup_place') ?: $settings->get('organization.address')));

        return $place !== '' ? preg_replace('/\s*\R\s*/', ', ', $place) : null;
    }

    /**
     * Extra text of the settings for this message, with the variables filled in.
     */
    private function extraText(): ?string
    {
        $text = trim((string) (app(Settings::class)->get('loans.mail_texts')[$this->type()] ?? ''));
        if ($text === '') {
            return null;
        }

        return strtr($text, [
            '{number}' => $this->loan->number,
            '{pickup}' => self::time($this->loan->pickup_at),
            '{return}' => self::time($this->loan->return_at),
            '{responsible}' => (string) $this->loan->responsible?->name,
        ]);
    }
}
