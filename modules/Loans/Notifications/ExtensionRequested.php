<?php

namespace Modules\Loans\Notifications;

use App\Support\Notifications\NotificationMessage;
use Modules\Loans\Models\Loan;
use Modules\Loans\Models\LoanExtensionRequest;

/**
 * The borrower page asks for a later return time; to the members involved.
 */
class ExtensionRequested extends LoanEventNotification
{
    public function __construct(Loan $loan, public readonly LoanExtensionRequest $request)
    {
        parent::__construct($loan);
    }

    public function type(): string
    {
        return 'extension_requested';
    }

    public function message(object $notifiable): NotificationMessage
    {
        return $this->forStaff('Verlängerung angefragt', array_values(array_filter([
            "Für {$this->name()} wird eine Verlängerung bis ".self::time($this->request->requested_return_at).' angefragt (bisher '.self::time($this->loan->return_at).').',
            $this->request->note ? "Notiz: {$this->request->note}" : null,
            'Annehmen oder ablehnen geht auf der Buchung.',
        ])), 'Verlängerung bis '.self::time($this->request->requested_return_at).' angefragt.', 'verlaengerung');
    }
}
