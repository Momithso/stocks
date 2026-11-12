<?php

namespace Modules\Loans\Notifications;

use App\Support\Notifications\NotificationMessage;
use Modules\Loans\Enums\DocumentType;
use Modules\Loans\Models\Loan;
use Modules\Loans\Models\LoanDocument;
use Modules\Loans\Support\LoanDocuments;

/**
 * The material was issued: contract and material list as pdf. Always by mail.
 */
class LoanIssued extends LoanEventNotification
{
    /**
     * @param  list<int>  $documentIds  documents of this issue (contract, material list)
     */
    public function __construct(Loan $loan, public readonly array $documentIds = [], ?int $sentBy = null)
    {
        parent::__construct($loan, $sentBy);
    }

    public function type(): string
    {
        return 'issued';
    }

    public function message(object $notifiable): NotificationMessage
    {
        $message = $this->forContact('Ausgabe erfolgt', [
            "das Material deiner Ausleihe {$this->name()} ist ausgegeben. Im Anhang sind der Leihvertrag und die Materialliste.",
            'Rückgabe bis '.self::time($this->loan->return_at).'. Brauchst du es länger, frag auf der Verleihseite eine Verlängerung an.',
        ], 'Ausgegeben. Rückgabe bis '.self::time($this->loan->return_at).'.');

        $documents = $this->documentIds
            ? LoanDocument::whereIn('id', $this->documentIds)->get()
            : $this->loan->documents()->whereIn('type', [DocumentType::Contract, DocumentType::MaterialList])->get();
        foreach ($documents as $document) {
            $message->attach("{$document->type->label()} {$this->loan->number}".($documents->where('type', $document->type)->count() > 1 ? " ({$document->id})" : '').'.pdf', fn () => app(LoanDocuments::class)->pdf($document));
        }

        return $message;
    }
}
