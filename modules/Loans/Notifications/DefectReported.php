<?php

namespace Modules\Loans\Notifications;

use App\Support\Notifications\NotificationMessage;

/**
 * A defect was found at the return or the inspection.
 */
class DefectReported extends LoanEventNotification
{
    public function type(): string
    {
        return 'defect';
    }

    public function message(object $notifiable): NotificationMessage
    {
        $defects = $this->loan->loanItems()->whereNotNull('return_state')->where('return_state', '!=', 'ok')->with('item')->get()
            ->map(fn ($row) => "{$row->item->name} ({$row->physicalItem()->number}): {$row->return_state->label()}".($row->return_note ? ", {$row->return_note}" : ''));

        return $this->forContact('Mangel gemeldet', [
            "bei der Rückgabe deiner Ausleihe {$this->name()} haben wir Folgendes festgestellt:",
            ...$defects->all(),
            'Wir melden uns, wie es weitergeht. Fragen kannst du im Chat stellen.',
        ], 'Bei der Rückgabe wurde ein Mangel festgestellt.', 'chat');
    }
}
