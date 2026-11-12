<?php

namespace Modules\Loans\Notifications;

use App\Support\Notifications\NotificationMessage;
use Illuminate\Support\Carbon;
use Modules\Loans\Models\Loan;

/**
 * The loan is overdue, in stages ("loans.overdue_days", default after 1, 3 and 7 days). To the
 * borrower always by mail; from the second stage also to the members involved.
 */
class LoanOverdue extends LoanEventNotification
{
    public function __construct(Loan $loan, public readonly int $level, public readonly bool $toStaff = false, ?int $sentBy = null)
    {
        parent::__construct($loan, $sentBy);
    }

    public function type(): string
    {
        return $this->toStaff ? 'overdue_staff' : 'overdue';
    }

    public function stage(): ?int
    {
        return $this->level;
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
        $days = max(1, $this->loan->overdueDays());
        $since = $days === 1 ? 'seit einem Tag' : "seit {$days} Tagen";

        if ($this->toStaff) {
            return $this->forStaff('Überfällig', [
                "{$this->name()} ist {$since} überfällig (Stufe {$this->level}). Verantwortlich: {$this->loan->responsibleName()}.",
            ], "Überfällig {$since}, Stufe {$this->level}.");
        }

        return $this->forContact('Überfällig', [
            "deine Ausleihe {$this->name()} sollte am ".self::time($this->loan->return_at)." zurück sein und ist {$since} überfällig.",
            'Bitte bring das Material so bald wie möglich zurück oder melde dich im Chat, wenn etwas dazwischengekommen ist.',
        ], "Rückgabe {$since} überfällig.", 'chat', 'Chat öffnen');
    }
}
