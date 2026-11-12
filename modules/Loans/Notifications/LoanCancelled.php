<?php

namespace Modules\Loans\Notifications;

use App\Support\Notifications\NotificationMessage;

/**
 * A confirmed loan was cancelled.
 */
class LoanCancelled extends LoanEventNotification
{
    public function type(): string
    {
        return 'cancelled';
    }

    public function message(object $notifiable): NotificationMessage
    {
        return $this->forContact('Storniert', array_values(array_filter([
            "deine Ausleihe {$this->name()} wurde storniert.",
            $this->loan->cancelled_reason ? "Grund: {$this->loan->cancelled_reason}" : null,
        ])), 'Deine Ausleihe wurde storniert.', 'chat', 'Chat öffnen');
    }
}
