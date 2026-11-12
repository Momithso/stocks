<?php

namespace Modules\Loans\Notifications;

use App\Support\Notifications\NotificationMessage;
use Modules\Loans\Models\Loan;
use Modules\Loans\Models\LoanMessage;

/**
 * A new message in the chat of a loan, to the other side. A tap on the push opens the chat.
 */
class NewChatMessage extends LoanEventNotification
{
    public function __construct(Loan $loan, public readonly LoanMessage $chatMessage, public readonly bool $toStaff)
    {
        parent::__construct($loan);
    }

    public function type(): string
    {
        return $this->toStaff ? 'chat_staff' : 'chat';
    }

    public function message(object $notifiable): NotificationMessage
    {
        $body = mb_strimwidth($this->chatMessage->body, 0, 500, '…');
        if ($this->toStaff) {
            return $this->forStaff('Neue Nachricht', ["{$this->chatMessage->author_name} schreibt zu {$this->name()}:", $body], "{$this->chatMessage->author_name}: {$this->chatMessage->body}", 'chat');
        }

        return $this->forContact('Neue Nachricht', ["{$this->chatMessage->author_name} hat dir zu deiner Ausleihe {$this->name()} geschrieben:", $body], "{$this->chatMessage->author_name}: {$this->chatMessage->body}", 'chat', 'Antworten');
    }
}
