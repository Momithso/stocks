<?php

namespace Modules\Loans\Notifications;

use App\Support\Notifications\NotificationMessage;
use Illuminate\Support\Carbon;

/**
 * Reminder before the return time ("loans.remind_return_hours").
 */
class ReturnReminder extends LoanEventNotification
{
    public function type(): string
    {
        return 'return_reminder';
    }

    /**
     * {@inheritDoc}
     */
    public function dueAt(): ?Carbon
    {
        return $this->loan->return_at;
    }

    public function message(object $notifiable): NotificationMessage
    {
        $place = self::place();

        return $this->forContact('Erinnerung an die Rückgabe', [
            "die Rückgabe deiner Ausleihe {$this->name()} ist am ".self::time($this->loan->return_at).($place ? " bei {$place}" : '').'.',
            'Auf der Verleihseite kannst du abhaken, ob alles wieder beisammen ist, oder eine Verlängerung anfragen.',
        ], 'Rückgabe am '.self::time($this->loan->return_at).'.', 'material');
    }
}
