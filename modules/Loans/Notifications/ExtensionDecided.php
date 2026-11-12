<?php

namespace Modules\Loans\Notifications;

use App\Support\Notifications\NotificationMessage;
use Modules\Loans\Models\Loan;
use Modules\Loans\Models\LoanExtensionRequest;

/**
 * The request for a later return time was accepted or declined.
 */
class ExtensionDecided extends LoanEventNotification
{
    public function __construct(Loan $loan, public readonly LoanExtensionRequest $request)
    {
        parent::__construct($loan);
    }

    public function type(): string
    {
        return 'extension_decided';
    }

    public function message(object $notifiable): NotificationMessage
    {
        $accepted = $this->request->status === LoanExtensionRequest::ACCEPTED;

        return $this->forContact($accepted ? 'Verlängerung angenommen' : 'Verlängerung abgelehnt', array_values(array_filter([
            $accepted
                ? "deine Ausleihe {$this->name()} läuft jetzt bis ".self::time($this->loan->return_at).'.'
                : "die Verlängerung deiner Ausleihe {$this->name()} ist leider nicht möglich. Die Rückgabe bleibt am ".self::time($this->loan->return_at).'.',
            $this->request->answer ? "Antwort: {$this->request->answer}" : null,
        ])), $accepted ? 'Verlängert bis '.self::time($this->loan->return_at).'.' : 'Verlängerung abgelehnt.');
    }
}
