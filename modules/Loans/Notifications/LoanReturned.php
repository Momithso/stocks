<?php

namespace Modules\Loans\Notifications;

use App\Support\Notifications\NotificationMessage;
use Modules\Loans\Models\Loan;
use Modules\Loans\Models\LoanDocument;
use Modules\Loans\Support\LoanDocuments;

/**
 * A return was booked, with the receipt as pdf. Always by mail. Accepted unchecked: with the note
 * that the inspection follows.
 */
class LoanReturned extends LoanEventNotification
{
    public function __construct(Loan $loan, public readonly ?int $documentId = null, ?int $sentBy = null)
    {
        parent::__construct($loan, $sentBy);
    }

    public function type(): string
    {
        return 'returned';
    }

    public function message(object $notifiable): NotificationMessage
    {
        $document = $this->documentId ? LoanDocument::find($this->documentId) : $this->loan->documents()->where('type', 'return_receipt')->latest('id')->first();
        $unchecked = (bool) ($document?->snapshot['unchecked'] ?? false);

        $message = $this->forContact('Rückgabe gebucht', array_values(array_filter([
            "wir haben die Rückgabe deiner Ausleihe {$this->name()} gebucht. Im Anhang ist der Rückgabebeleg.",
            $unchecked ? 'Das Material wurde ungeprüft angenommen, Mängel vorbehalten. Wir prüfen es noch und melden uns, falls etwas fehlt oder kaputt ist.' : null,
            $this->loan->loanItems()->whereNull('returned_at')->exists() ? 'Ein Teil ist noch nicht zurück.' : null,
        ])), $unchecked ? 'Rückgabe ungeprüft angenommen.' : 'Rückgabe gebucht. Danke!');

        if ($document) {
            $message->attach("Rückgabebeleg {$this->loan->number}.pdf", fn () => app(LoanDocuments::class)->pdf($document));
        }

        return $message;
    }
}
