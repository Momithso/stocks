<?php

namespace Modules\Loans\Support;

use App\Support\Notifications\NotificationEvents;
use App\Support\Notifications\WebPush;
use App\Support\Settings;
use Modules\Loans\Enums\DocumentType;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Enums\ReturnState;
use Modules\Loans\Models\Contact;
use Modules\Loans\Models\Loan;
use Modules\Loans\Models\LoanDocument;
use Modules\Loans\Models\LoanExtensionRequest;
use Modules\Loans\Models\LoanItem;
use Modules\Loans\Models\LoanMessage;

/**
 * What the borrower page shows (documentation/LOANS.md): the state of the loan for the borrower,
 * contacts of both sides, documents, defects, the checklist and the chat. Never locations, other
 * loans or signature images outside the pdfs.
 */
class LoanPageData
{
    public function __construct(
        private readonly Settings $settings,
        private readonly LoanDocuments $documents,
        private readonly LoanNotifier $notifier,
        private readonly NotificationEvents $events,
    ) {}

    /**
     * Props of the page.
     *
     * @return array<string, mixed>
     */
    public function page(Loan $loan): array
    {
        $loan->load(['borrower.council', 'responsible', 'event', 'documents']);

        return [
            'loan' => $this->state($loan),
            'contacts' => $this->contacts($loan),
            'documents' => $loan->documents->reject(fn (LoanDocument $document) => $document->type === DocumentType::PaperScan)->map(fn (LoanDocument $document) => [
                'id' => $document->id,
                'label' => $document->type->label(),
                'created_at' => $document->created_at->toIso8601String(),
                'url' => route('loans.page.document', ['loan' => $loan->id, 'token' => LoanPage::token($loan), 'document' => $document->id], absolute: false),
            ])->values(),
            'defects' => $this->defects($loan),
            'rows' => $this->rows($loan),
            'messages' => $this->messages($loan),
            'notifications' => [
                'events' => $this->events->table(NotificationEvents::CONTACTS, $loan->notificationPreferences()),
                'push_key' => WebPush::publicKey(),
                'push_devices' => $loan->pushSubscriptions()->count(),
            ],
            'base' => LoanPage::path($loan),
        ];
    }

    /**
     * State for the head and the live update: status for the borrower, times, pin, extension.
     *
     * @return array<string, mixed>
     */
    public function state(Loan $loan): array
    {
        $status = $this->status($loan);
        $request = $loan->extensionRequests()->first();
        $place = trim((string) ($this->settings->get('loans.pickup_place') ?: $this->settings->get('organization.address')));

        return [
            'number' => $loan->number,
            'purpose' => $loan->purpose,
            'event' => $loan->event?->name,
            'status' => $status['key'],
            'status_label' => $status['label'],
            'status_variant' => $status['variant'],
            'sentence' => $this->sentence($loan, $status['key']),
            'step' => $this->step($loan),
            'pickup_at' => $loan->pickup_at->toIso8601String(),
            'return_at' => $loan->return_at->toIso8601String(),
            'place' => $place !== '' ? preg_replace('/\s*\R\s*/', ', ', $place) : null,
            'cancelled_reason' => $loan->status === LoanStatus::Cancelled ? $loan->cancelled_reason : null,
            // the pack pin only while it counts
            'pack_pin' => in_array($loan->status, LoanStatus::reserving(), true) ? $loan->pack_pin : null,
            'can_extend' => $loan->status === LoanStatus::Issued && $request?->status !== LoanExtensionRequest::OPEN,
            'extension' => $request?->summary(),
            'checklist' => $loan->status === LoanStatus::Issued,
            'unread' => $loan->messages()->where('sender', '!=', LoanMessage::CONTACT)->whereNull('read_by_contact_at')->count(),
        ];
    }

    /**
     * Rows of the material: the positions with the current contents of boxes before the issue
     * (read only), afterwards the rows that left with their tick of the checklist.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(Loan $loan): array
    {
        $left = $loan->loanItems()->whereNotNull('issued_at')->with(['item', 'issuedItem'])->get();
        if ($left->isEmpty()) {
            return array_map(fn (array $row) => [
                'id' => null,
                'parent_id' => null,
                'depth' => $row['depth'],
                'number' => $row['number'],
                'name' => $row['name'],
                'amount' => $row['bulk'] ? "{$row['quantity']} {$row['unit']}" : null,
                'is_container' => false,
                'checked' => false,
            ], $this->documents->positions($loan));
        }

        return array_map(fn (array $row) => [
            'id' => $row['id'],
            'parent_id' => $row['parent_id'],
            'depth' => $row['depth'],
            'number' => $row['number'],
            'name' => $row['name'],
            'amount' => $row['kind'] === 'bulk' ? "{$row['quantity']} {$row['unit']}" : null,
            'is_container' => $row['is_container'],
            'checked' => $row['checked'],
        ], RowTree::flatten($left, fn (LoanItem $row) => ['checked' => $row->borrower_checked_at !== null]));
    }

    /**
     * Messages of the chat after an id, from the point of view of a side ($userId: the member who
     * reads, for "mine").
     *
     * @return list<array<string, mixed>>
     */
    public function messages(Loan $loan, int $after = 0, string $side = 'contact', ?int $userId = null): array
    {
        return $loan->messages()->where('id', '>', $after)->get()->map(fn (LoanMessage $message) => $message->summary($side, $userId))->all();
    }

    /**
     * Mark the messages of the other side as read and end the pause of the chat notifications.
     */
    public function markRead(Loan $loan, string $side): void
    {
        $column = $side === 'contact' ? 'read_by_contact_at' : 'read_by_staff_at';
        $loan->messages()->where('sender', $side === 'contact' ? '!=' : '=', LoanMessage::CONTACT)->whereNull($column)->update([$column => now()]);
        LoanNotifier::markRead($loan, $side === 'staff');
    }

    /**
     * Status as the borrower sees it.
     *
     * @return array{key: string, label: string, variant: string}
     */
    private function status(Loan $loan): array
    {
        [$key, $label, $variant] = match (true) {
            $loan->isNotPickedUp() => ['not_picked_up', 'Nicht abgeholt', 'danger'],
            $loan->isOverdue() => ['overdue', 'Überfällig', 'danger'],
            default => [$loan->status->value, $loan->status->label(), $loan->status->variant()],
        };

        return compact('key', 'label', 'variant');
    }

    /**
     * One sentence on the state, e.g. "Noch 3 Tage bis zur Rückgabe".
     */
    private function sentence(Loan $loan, string $status): string
    {
        $until = function (\DateTimeInterface $time, string $what): string {
            $days = (int) now()->startOfDay()->diffInDays($time->copy()->startOfDay());

            return match (true) {
                $days <= 0 => "{$what} heute um ".$time->format('H:i').' Uhr',
                $days === 1 => "{$what} morgen um ".$time->format('H:i').' Uhr',
                default => "Noch {$days} Tage bis zur ".($what === 'Abholung' ? 'Abholung' : 'Rückgabe'),
            };
        };

        return match ($status) {
            // the pickup time passed, the loan still holds its time
            'confirmed' => $loan->pickup_at->isPast()
                ? 'Die Abholung war am '.$loan->pickup_at->format('d.m. \u\m H:i').' Uhr. Komm bitte bald vorbei oder melde dich im Chat.'
                : $until($loan->pickup_at, 'Abholung'),
            'not_picked_up' => 'Die Abholung hat nicht stattgefunden. Melde dich bitte im Chat.',
            'issued' => $until($loan->return_at, 'Rückgabe'),
            'overdue' => ($loan->overdueDays() === 1 ? 'Seit einem Tag' : "Seit {$loan->overdueDays()} Tagen").' überfällig. Bitte bring das Material zurück.',
            'unchecked' => 'Das Material ist zurück. Wir prüfen es noch.',
            'closed' => 'Alles zurück und geprüft. Danke!',
            'defects' => 'Zurück mit Mängeln. Details unten.',
            'cancelled' => 'Die Ausleihe wurde storniert.',
            default => '',
        };
    }

    /**
     * Step of the progress: 0 confirmed, 1 picked up, 2 back, 3 checked; -1 cancelled.
     */
    private function step(Loan $loan): int
    {
        return match ($loan->status) {
            LoanStatus::Cancelled => -1,
            LoanStatus::Confirmed, LoanStatus::Draft => 0,
            LoanStatus::Issued => 1,
            LoanStatus::ReturnedUnchecked => 2,
            LoanStatus::Closed, LoanStatus::ClosedWithDefects => 3,
        };
    }

    /**
     * Contacts of both sides: borrower and responsible person, the organization with its central
     * number and the members involved (names only, accounts have no phone).
     *
     * @return array<string, mixed>
     */
    private function contacts(Loan $loan): array
    {
        $person = fn (?Contact $contact) => $contact ? [
            'name' => $contact->name,
            'phone' => $contact->phone,
            'email' => $contact->email,
            'address' => $contact->address() ?: null,
            'affiliation' => $contact->council?->name,
        ] : null;

        return [
            'responsible' => $person($loan->responsible),
            'borrower' => $loan->borrower_contact_id !== $loan->responsible_contact_id ? $person($loan->borrower) : null,
            'organization' => [
                'name' => $this->settings->get('organization.name'),
                'phone' => $this->settings->get('organization.phone'),
                'email' => $this->settings->get('legal.contact_email'),
            ],
            'members' => $this->notifier->staff($loan)->map(fn ($user) => $user->name)->values()->all(),
        ];
    }

    /**
     * Defects found at the return or the inspection, with what is still missing. Only booked ones
     * (return or inspection finished, as on the receipt): while it runs, a mark may still change.
     *
     * @return list<array{name: string, number: string, state: string, note: string|null}>
     */
    private function defects(Loan $loan): array
    {
        return $loan->loanItems()->whereNotNull('inspected_at')->whereNotNull('return_state')->where('return_state', '!=', ReturnState::Ok->value)->with(['item', 'issuedItem'])->get()
            ->map(fn (LoanItem $row) => [
                'name' => $row->item->name,
                'number' => $row->physicalItem()->number,
                'state' => $row->return_state->label(),
                'note' => $row->return_note,
            ])->values()->all();
    }
}
