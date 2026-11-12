<?php

namespace Modules\Loans\Notifications;

use App\Support\Notifications\NotificationMessage;
use Modules\Loans\Support\LoanDocuments;

/**
 * The loan is confirmed: link, time and pack pin, with the booking confirmation as pdf. Always by
 * mail; can be left out in the dialog of the confirmation.
 */
class LoanConfirmed extends LoanEventNotification
{
    public function type(): string
    {
        return 'confirmed';
    }

    public function message(object $notifiable): NotificationMessage
    {
        $place = self::place();
        $message = $this->forContact('Buchung bestätigt', [
            "deine Ausleihe {$this->name()} ist bestätigt.",
            'Abholung: '.self::time($this->loan->pickup_at).($place ? " bei {$place}" : '').'. Rückgabe: '.self::time($this->loan->return_at).'.',
            "Deine Pack-PIN ist {$this->loan->pack_pin}. Damit bestätigst du auf der Seite einer Box, dass du ihren Inhalt kontrolliert hast.",
        ], 'Deine Ausleihe ist bestätigt.');

        return $message->attach("Buchungsbestätigung {$this->loan->number}.pdf", fn () => app(LoanDocuments::class)->confirmation($this->loan));
    }
}
