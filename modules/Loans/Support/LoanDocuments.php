<?php

namespace Modules\Loans\Support;

use App\Models\Item;
use App\Models\User;
use App\Support\Branding;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\Loans\Enums\DocumentType;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Enums\ReturnState;
use Modules\Loans\Models\Contact;
use Modules\Loans\Models\Loan;
use Modules\Loans\Models\LoanDocument;
use Modules\Loans\Models\LoanItem;

/**
 * Documents of a loan: what is frozen at the issue and at every return (snapshot), and the pdf
 * made from it with dompdf. The pdf is made when it is downloaded; the snapshot and its hash are
 * what was signed, so the pdf always shows exactly that.
 */
class LoanDocuments
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ContractTemplate $template,
        private readonly Branding $branding,
        private readonly Availability $availability,
    ) {}

    /**
     * Content of contract and material list at the issue. Before the issue the same content serves
     * as preview: the positions then show the current contents of the boxes.
     *
     * @return array<string, mixed>
     */
    public function issueSnapshot(Loan $loan, User $issuer, ?EloquentCollection $positions = null, bool $supplement = false): array
    {
        $loan->loadMissing(['borrower.council', 'responsible.council', 'responsibleUser', 'event']);
        $values = $this->variables($loan, $issuer->name);

        return [
            ...$this->head($loan),
            'issuer' => $issuer->name,
            'lender' => $values['lender'],
            'borrower' => $this->person($loan->borrower ?? $loan->responsible),
            'responsible' => $loan->isInternal() ? ['name' => $loan->responsibleUser?->name] : $this->person($loan->responsible),
            'contract' => $loan->isInternal() ? null : ContractTemplate::render($this->template->text(), $values),
            'positions' => $this->positions($loan, $positions),
            'supplement' => $supplement,
            'date' => now()->format('d.m.Y H:i'),
        ];
    }

    /**
     * Content of a return receipt: the rows booked with this return. Rows that were not checked
     * are accepted "ungeprüft, Mängel vorbehalten".
     *
     * @param  Collection<int, LoanItem>  $rows
     * @return array<string, mixed>
     */
    public function returnSnapshot(Loan $loan, Collection $rows, string $returner, User $receiver): array
    {
        return [
            ...$this->head($loan),
            'returner' => $returner,
            'receiver' => $receiver->name,
            'unchecked' => $rows->contains(fn (LoanItem $row) => ! $row->isReturnHandled()),
            'rows' => $rows->map(fn (LoanItem $row) => [
                'number' => $row->physicalItem()->number,
                'name' => $row->item->name,
                'depth' => $row->parent_id ? 1 : 0,
                'quantity' => $row->isBulk() ? ($row->returned_quantity ?? $row->quantity) : null,
                'of' => $row->isBulk() ? $row->quantity : null,
                'unit' => $row->item->unit,
                'state' => $row->isReturnHandled() ? ($row->return_state ?? ReturnState::Ok)->label() : 'ungeprüft',
                'note' => implode('; ', array_filter([$row->return_note, $row->issueDefect() ? "schon bei der Ausgabe: {$row->issueDefect()}" : null])) ?: null,
                'manual' => $row->return_checked_at !== null && ! $row->return_checked_by_scan,
            ])->values()->all(),
            'date' => now()->format('d.m.Y H:i'),
        ];
    }

    /**
     * Pdf of a stored document.
     */
    public function pdf(LoanDocument $document): string
    {
        $document->loadMissing('signatures', 'loan');

        // the code of the borrower page is added when the pdf is made, the snapshot stays as signed
        return $this->render(match ($document->type) {
            DocumentType::Contract => 'loans::pdf.contract',
            DocumentType::MaterialList => 'loans::pdf.list',
            default => 'loans::pdf.receipt',
        }, $document->snapshot, SignatureInput::summaries($document), $document->hash, LoanPage::qrDataUri($document->loan));
    }

    /**
     * Pdf of contract or material list before the issue, without signatures: to read before signing
     * and to print for signing on paper.
     */
    public function preview(Loan $loan, DocumentType $type, User $issuer): string
    {
        // what leaves now: the ticked rows that did not leave yet, before ticking all of them
        $open = $loan->positions()->whereNull('issued_at')->get();
        $leaving = $open->whereNotNull('checked_at');

        return $this->render($type === DocumentType::Contract ? 'loans::pdf.contract' : 'loans::pdf.list', $this->issueSnapshot($loan, $issuer, $leaving->isEmpty() ? $open : $leaving, $loan->status === LoanStatus::Issued), [], null);
    }

    /**
     * Pdf of the booking confirmation from the current data, with the pack pin.
     */
    public function confirmation(Loan $loan): string
    {
        $loan->loadMissing(['borrower', 'responsible', 'responsibleUser', 'event']);

        return $this->render('loans::pdf.confirmation', [
            ...$this->head($loan),
            'borrower' => $this->person($loan->borrower ?? $loan->responsible),
            'responsible' => $loan->isInternal() ? ['name' => $loan->responsibleUser?->name] : $this->person($loan->responsible),
            'positions' => $this->positions($loan),
            'pin' => $loan->pack_pin,
            'date' => now()->format('d.m.Y'),
        ], [], null, LoanPage::qrDataUri($loan));
    }

    /**
     * Html of the contract text with example values, for the preview in the settings.
     */
    public function exampleContract(string $text): string
    {
        $values = array_combine(array_keys(ContractTemplate::VARIABLES), array_map(fn (string $label) => "[{$label}]", ContractTemplate::VARIABLES));

        return ContractTemplate::render($text, [...$values, 'lender' => $this->settings->get('loans.lender'), 'number' => 'B-'.now()->year.'-0042']);
    }

    /**
     * Values of the variables of the contract text.
     *
     * @return array<string, string|null>
     */
    public function variables(Loan $loan, string $issuer): array
    {
        $responsible = $loan->responsible;

        return [
            'number' => $loan->number,
            'lender' => $this->settings->get('loans.lender'),
            'issuer' => $issuer,
            'borrower' => ($loan->borrower ?? $responsible)?->name,
            'responsible' => $responsible?->name,
            'responsible_address' => $responsible?->address(),
            'responsible_phone' => $responsible?->phone,
            'responsible_email' => $responsible?->email,
            'pickup' => $loan->pickup_at->format('d.m.Y, H:i').' Uhr',
            'return' => $loan->return_at->format('d.m.Y, H:i').' Uhr',
            'purpose' => $loan->purpose,
            'contact' => $this->settings->get('legal.contact_email'),
            'privacy_url' => $this->settings->get('legal.privacy_url'),
            'place' => $this->place(),
            'date' => now()->format('d.m.Y'),
        ];
    }

    /**
     * Data every document starts with.
     *
     * @return array<string, mixed>
     */
    private function head(Loan $loan): array
    {
        return [
            'number' => $loan->number,
            'kind' => $loan->kind->value,
            'organization' => $this->settings->get('organization.name'),
            'address' => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $this->settings->get('organization.address'))))),
            'contact' => $this->settings->get('legal.contact_email'),
            'pickup' => $loan->pickup_at->format('d.m.Y, H:i'),
            'return' => $loan->return_at->format('d.m.Y, H:i'),
            'purpose' => $loan->purpose,
            'event' => $loan->event?->name,
        ];
    }

    /**
     * @return array<string, string|null>|null
     */
    private function person(?Contact $contact): ?array
    {
        return $contact ? [
            'name' => $contact->name,
            'address' => $contact->address(),
            'phone' => $contact->phone,
            'email' => $contact->email,
            'affiliation' => $contact->affiliation(),
        ] : null;
    }

    /**
     * Positions with the contents of boxes below them: the frozen rows after the issue, the current
     * contents before.
     *
     * @return list<array<string, mixed>>
     */
    public function positions(Loan $loan, ?EloquentCollection $positions = null): array
    {
        $positions = $positions?->load(['item.condition', 'issuedItem', 'children.item.condition', 'boxChecks'])
            ?? $loan->positions()->with(['item.condition', 'issuedItem', 'children.item.condition', 'boxChecks'])->get();

        $rows = [];
        foreach ($positions as $position) {
            $rows[] = $this->row($position->physicalItem(), $position->item, $position->quantity, 0, $position->condition_at_issue, $this->notes($this->boxNote($position), $position));
            // the rows of the contents exist from the start of the issue, and stay as they left
            $frozen = $position->issued_at !== null || $position->children->isNotEmpty();
            $rows = [...$rows, ...($frozen ? $this->frozenRows($position, 1) : $this->liveRows($position->item, 1))];
        }

        return $rows;
    }

    /**
     * Note of a row with the defect found at the issue.
     */
    private function notes(?string $note, LoanItem $row): ?string
    {
        $defect = $row->issueDefect() ? "Mangel bei der Ausgabe: {$row->issueDefect()}" : null;

        return implode('; ', array_filter([$note, $defect])) ?: null;
    }

    /**
     * Rows of the frozen contents of a box.
     *
     * @return list<array<string, mixed>>
     */
    private function frozenRows(LoanItem $parent, int $depth): array
    {
        $rows = [];
        foreach ($parent->children()->with(['item.condition', 'children'])->get() as $child) {
            $rows[] = $this->row($child->item, $child->item, $child->quantity, $depth, $child->condition_at_issue, $this->notes(null, $child));
            $rows = [...$rows, ...$this->frozenRows($child, $depth + 1)];
        }

        return $rows;
    }

    /**
     * Rows of the current contents of a box.
     *
     * @return list<array<string, mixed>>
     */
    private function liveRows(Item $box, int $depth): array
    {
        if (! $box->is_container || $depth > 20) {
            return [];
        }

        $rows = [];
        foreach ($box->contents()->active()->with('condition')->get() as $item) {
            $rows[] = $this->row($item, $item, $item->quantity, $depth, null, null);
            $rows = [...$rows, ...$this->liveRows($item, $depth + 1)];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Item $physical, Item $item, int $quantity, int $depth, ?string $condition, ?string $note): array
    {
        return [
            'number' => $physical->number,
            'name' => $item->name,
            'quantity' => $quantity,
            'unit' => $item->unit,
            'bulk' => $item->unit !== null,
            'condition' => $condition ?? $item->condition?->name,
            'depth' => $depth,
            'note' => $note,
        ];
    }

    /**
     * Note at a box: who checked it with the pack pin, and whether it came back unchecked before.
     */
    private function boxNote(LoanItem $position): ?string
    {
        $notes = [];
        foreach ($position->boxChecks as $check) {
            $notes[] = "kontrolliert von {$check->name} am {$check->checked_at->format('d.m.Y H:i')} (".count($check->ticks)." von {$check->total} abgehakt)";
        }
        if ($position->item->is_container) {
            foreach ($this->availability->check($position->item, 1, now(), now()->addMinute(), $position->loan)['hints'] as $hint) {
                if (str_starts_with($hint, 'Ungeprüft')) {
                    $notes[] = $hint;
                }
            }
        }

        return $notes ? implode('; ', $notes) : null;
    }

    /**
     * Place of the signature: the setting, or the city of the address of the organization.
     */
    private function place(): ?string
    {
        if ($place = $this->settings->get('loans.place')) {
            return $place;
        }
        $lines = preg_split('/\R/', trim((string) $this->settings->get('organization.address')));

        return trim(preg_replace('/^\d{4,5}\s*/', '', (string) end($lines))) ?: null;
    }

    /**
     * Render a pdf view.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  list<array<string, mixed>>  $signatures
     */
    private function render(string $view, array $snapshot, array $signatures, ?string $hash, ?string $qr = null): string
    {
        $logo = $this->branding->contents($this->branding->printKind());

        return Pdf::setPaper('a4')->loadView($view, [
            'doc' => $snapshot,
            'signatures' => $signatures,
            'hash' => $hash,
            'qr' => $qr,
            'logo' => $logo !== null ? 'data:image/png;base64,'.base64_encode($this->png($logo)) : null,
        ])->output();
    }

    /**
     * Image as png scaled down to 400 px (dompdf can not read every format, a big logo would fill
     * the memory on every document), cached by content so it is decoded only once.
     */
    private function png(string $content): string
    {
        return Cache::rememberForever('loan-documents.logo.'.md5($content), function () use ($content): string {
            $source = @imagecreatefromstring($content);
            if ($source === false) {
                return '';
            }
            $scale = min(1, 400 / max(imagesx($source), imagesy($source)));
            $image = imagescale($source, max(1, (int) round(imagesx($source) * $scale)), max(1, (int) round(imagesy($source) * $scale)));
            unset($source);
            imagesavealpha($image, true);
            ob_start();
            imagepng($image);

            return (string) ob_get_clean();
        });
    }
}
