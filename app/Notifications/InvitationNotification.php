<?php

namespace App\Notifications;

use App\Support\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mail with the link to accept an invitation.
 */
class InvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $token  plain token of the link, only the hash is stored
     */
    public function __construct(
        public readonly string $token,
        public readonly string $invitedBy,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $organization = app(Settings::class)->get('organization.name');

        return (new MailMessage)
            ->subject("Einladung zu Stocks von {$organization}")
            ->greeting('Hallo!')
            ->line("{$this->invitedBy} hat dich zu Stocks eingeladen, der Inventar- und Verleihverwaltung von {$organization}.")
            ->action('Konto anlegen', route('invitation.show', ['token' => $this->token]))
            ->line('Der Link gilt '.config('stocks.invitation_lifetime_days').' Tage und funktioniert nur einmal.');
    }
}
