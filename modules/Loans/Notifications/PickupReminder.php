<?php

namespace Modules\Loans\Notifications;

use App\Support\Notifications\NotificationMessage;
use Illuminate\Support\Carbon;

/**
 * Reminder before the pickup ("loans.remind_pickup_hours").
 */
class PickupReminder extends LoanEventNotification
{
    public function type(): string
    {
        return 'pickup_reminder';
    }

    /**
     * {@inheritDoc}
     */
    public function dueAt(): ?Carbon
    {
        return $this->loan->pickup_at;
    }

    public function message(object $notifiable): NotificationMessage
    {
        $place = self::place();

        return $this->forContact('Erinnerung an die Abholung', [
            "die Abholung deiner Ausleihe {$this->name()} ist am ".self::time($this->loan->pickup_at).($place ? " bei {$place}" : '').'.',
            'Auf der Verleihseite siehst du, was dazugehört, und kannst beim Packen abhaken.',
        ], 'Abholung am '.self::time($this->loan->pickup_at).'.', 'material');
    }
}
