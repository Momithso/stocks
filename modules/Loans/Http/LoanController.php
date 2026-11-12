<?php

namespace Modules\Loans\Http;

use App\Enums\ItemKind;
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\User;
use App\Support\Surface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Loans\Actions\CancelLoan;
use Modules\Loans\Actions\ChangePositions;
use Modules\Loans\Actions\ConfirmLoan;
use Modules\Loans\Actions\ExtendLoan;
use Modules\Loans\Actions\IssueLoan;
use Modules\Loans\Actions\ReturnLoan;
use Modules\Loans\Actions\SaveLoan;
use Modules\Loans\Actions\StowItem;
use Modules\Loans\Enums\DocumentType;
use Modules\Loans\Enums\LoanKind;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Enums\ReturnState;
use Modules\Loans\Models\Contact;
use Modules\Loans\Models\Council;
use Modules\Loans\Models\Event;
use Modules\Loans\Models\Loan;
use Modules\Loans\Models\LoanDocument;
use Modules\Loans\Models\LoanItem;
use Modules\Loans\Support\Availability;
use Modules\Loans\Support\LoanDocuments;
use Modules\Loans\Support\LoansTable;
use Modules\Loans\Support\RowTree;
use Modules\Loans\Support\SignatureInput;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Loans: list, detail, form, confirm, extend, cancel, pin, documents.
 */
class LoanController extends Controller
{
    /**
     * Display the list of loans, also as live update.
     */
    public function index(Request $request): Response|JsonResponse
    {
        $table = new LoansTable($request);
        if ($table->wantsChanges()) {
            return $table->changesResponse();
        }

        return Inertia::render('Loans::Loans/Index', [
            'loans' => $table->scroll(),
            'statuses' => [['value' => 'open', 'label' => 'Offen (alles außer abgeschlossen)'], ['value' => 'overdue', 'label' => 'Überfällig'], ...LoanStatus::options()],
            'events' => Event::options(),
            'filters' => $request->only(['status', 'from', 'to', 'contact', 'event']),
            'contact' => $request->integer('contact') ? Contact::find($request->integer('contact'))?->only(['id', 'name']) : null,
        ]);
    }

    /**
     * Display the form of a new loan. An event, a day and an item can be given as suggestion.
     */
    public function create(Request $request): Response
    {
        $event = Event::with('responsible')->find($request->integer('event'));
        $day = $request->date('date') ?? now();

        return $this->form(null, [
            'kind' => LoanKind::External->value,
            'event_id' => $event?->id,
            'pickup_at' => ($event?->starts_at ?? $day->copy()->setTime(16, 0))->format('Y-m-d\TH:i'),
            'return_at' => ($event?->ends_at ?? $day->copy()->addDay()->setTime(12, 0))->format('Y-m-d\TH:i'),
            'responsible' => $event?->responsible?->summary(true),
            'borrower' => null,
            'item_id' => $request->integer('item') ?: null,
        ]);
    }

    /**
     * Create a loan as draft; an item from the scan tab becomes its first position.
     */
    public function store(Request $request, SaveLoan $saveLoan, ChangePositions $positions): RedirectResponse
    {
        $loan = $saveLoan->handle($request->user(), null, $this->validated($request));

        if ($item = Item::find($request->integer('item_id'))) {
            $positions->add($loan, $item);
        }

        return to_route('loans.show', $loan)->with('success', "{$loan->number} wurde angelegt.");
    }

    /**
     * Display a loan with its positions, availability and documents.
     */
    public function show(Request $request, Loan $loan, Availability $availability, LoanDocuments $documents, ConfirmLoan $confirmLoan): Response
    {
        $loan->load(['borrower.council', 'responsible.council', 'responsibleUser', 'issuer', 'event', 'creator', 'documents.signatures']);
        $canManage = $request->user()->can('loans.manage');
        $checks = $loan->status->isOpen() ? $availability->forLoan($loan) : [];
        // signed papers carry contact data and signatures: only for those who issue or manage
        $documentsVisible = self::canSeeDocuments($request->user());

        return Inertia::render('Loans::Loans/Show', [
            'loan' => [
                ...$loan->summary(),
                ...$loan->only(['purpose', 'notes', 'cancelled_reason', 'defects_note']),
                'kind_label' => $loan->kind->label(),
                'borrower' => $loan->borrower?->summary($canManage),
                'responsible' => $loan->isInternal() ? ['id' => null, 'name' => $loan->responsibleUser?->name] : $loan->responsible?->summary($canManage),
                'issuer' => $loan->issuer?->name,
                'creator' => $loan->creator?->name,
                'event' => $loan->event?->only(['id', 'name']),
                'confirmed_at' => $loan->confirmed_at?->toIso8601String(),
                'issued_at' => $loan->issued_at?->toIso8601String(),
                'returned_at' => $loan->returned_at?->toIso8601String(),
                'inspected_at' => $loan->inspected_at?->toIso8601String(),
                'cancelled_at' => $loan->cancelled_at?->toIso8601String(),
                'defects_closed_at' => $loan->defects_closed_at?->toIso8601String(),
                // the pin is only for members who manage loans
                'pack_pin' => $canManage && in_array($loan->status, LoanStatus::reserving(), true) ? $loan->pack_pin : null,
            ],
            'positions' => $this->positions($loan, $checks),
            // still missing after the return, with box contents
            'missing' => $loan->loanItems()->stillMissing()->with('missingItem', 'item')->get()->map(fn (LoanItem $row) => [
                'id' => $row->id,
                'number' => $row->missingItem->number,
                'name' => $row->item->name,
                'quantity' => $row->missingItem->kind === ItemKind::Bulk ? $row->missingItem->quantity : null,
                'unit' => $row->item->unit,
            ]),
            'contents' => $documents->positions($loan),
            'problems' => $loan->status === LoanStatus::Draft ? [...$confirmLoan->responsibleProblems($loan), ...$availability->problems($loan)] : [],
            'documents' => ! $documentsVisible ? [] : $loan->documents->map(fn (LoanDocument $document) => [
                'id' => $document->id,
                'type' => $document->type->value,
                'label' => $document->type->label(),
                'created_at' => $document->created_at->toIso8601String(),
                'unchanged' => $document->type === DocumentType::PaperScan ? null : $document->isUnchanged(),
                'hash' => $document->hash,
                'unchecked' => (bool) ($document->snapshot['unchecked'] ?? false),
                'signatures' => SignatureInput::summaries($document),
                'url' => route('loans.document', [$loan, $document], absolute: false),
            ]),
            'pendingStow' => StowItem::pending()->where('loan_id', $loan->id)->count(),
            // rest of a partial issue that may still leave, or that missed its time
            'hasRest' => IssueLoan::hasRest($loan),
            'restExpired' => IssueLoan::restExpired($loan),
        ]);
    }

    /**
     * The loan in short for a window (json): positions with their defects, what is still missing,
     * and the steps the person may take here. Used on the kiosk, which has no loan page.
     */
    public function details(Request $request, Loan $loan): JsonResponse
    {
        $loan->load(['responsible', 'responsibleUser', 'borrower']);
        $user = $request->user();
        $openDefects = $loan->status === LoanStatus::ClosedWithDefects && $loan->defects_closed_at === null;

        return response()->json([
            'loan' => [
                ...$loan->summary(),
                'returned_at' => $loan->returned_at?->toIso8601String(),
                'defects_note' => $loan->defects_note,
                'open_defects' => $openDefects,
            ],
            'rows' => RowTree::flatten($loan->loanItems()->with(['item', 'issuedItem'])->get(), fn (LoanItem $row) => [
                'state' => $row->return_state?->value,
                'state_label' => $row->return_state?->label(),
                'note' => $row->return_note,
                'returned' => $row->returned_at !== null,
                'issued' => $row->issued_at !== null,
                'issue_defect' => $row->issueDefect(),
            ]),
            'missing' => $loan->loanItems()->stillMissing()->with('missingItem', 'item')->get()->map(fn (LoanItem $row) => [
                'id' => $row->id,
                'number' => $row->missingItem->number,
                'name' => $row->item->name,
            ]),
            'can_found' => $user->can('loans.issue') && Surface::has('loans.missing.found'),
            'can_close_defects' => $openDefects && $user->can('loans.cancel') && Surface::has('loans.close-defects'),
            'return_href' => $loan->status === LoanStatus::Issued && $user->can('loans.issue') ? Surface::link('loans.return.show', [$loan, 'return']) : null,
            'issue_href' => IssueLoan::hasRest($loan) && $user->can('loans.issue') ? Surface::link('loans.issue', $loan) : null,
            'inspect_href' => $loan->status === LoanStatus::ReturnedUnchecked && $user->can('loans.issue') ? Surface::link('loans.return.show', [$loan, 'inspect']) : null,
            'href' => Surface::link('loans.show', $loan),
        ]);
    }

    /**
     * Display the form of a loan.
     */
    public function edit(Loan $loan): Response
    {
        $loan->load(['borrower.council', 'responsible.council']);

        return $this->form($loan, [
            ...$loan->only(['id', 'number', 'event_id', 'responsible_user_id', 'purpose', 'notes']),
            'kind' => $loan->kind->value,
            'status' => $loan->status->value,
            'pickup_at' => $loan->pickup_at->format('Y-m-d\TH:i'),
            'return_at' => $loan->return_at->format('Y-m-d\TH:i'),
            'responsible' => $loan->responsible?->summary(true),
            'borrower' => $loan->borrower?->summary(true),
            'item_id' => null,
        ]);
    }

    /**
     * Change a loan.
     */
    public function update(Request $request, Loan $loan, SaveLoan $saveLoan): RedirectResponse
    {
        $saveLoan->handle($request->user(), $loan, $this->validated($request));

        return to_route('loans.show', $loan)->with('success', "{$loan->number} wurde gespeichert.");
    }

    /**
     * Delete a draft. Other loans are cancelled instead, so their history stays.
     */
    public function destroy(Loan $loan): RedirectResponse
    {
        if ($loan->status !== LoanStatus::Draft) {
            throw ValidationException::withMessages(['loan' => 'Nur Entwürfe lassen sich löschen. Bestätigte Buchungen werden storniert.']);
        }
        $loan->delete();

        return to_route('loans.index')->with('success', "{$loan->number} wurde gelöscht.");
    }

    /**
     * Confirm a draft.
     */
    public function confirm(Loan $loan, ConfirmLoan $confirmLoan): RedirectResponse
    {
        $confirmLoan->handle($loan);

        return back()->with('success', "{$loan->number} ist bestätigt, die Gegenstände sind reserviert.");
    }

    /**
     * Give an issued loan a later return time.
     */
    public function extend(Request $request, Loan $loan, ExtendLoan $extendLoan): RedirectResponse
    {
        $validated = $request->validate(['return_at' => ['required', 'date']]);
        $extendLoan->handle($loan, Carbon::parse($validated['return_at']));

        return back()->with('success', "{$loan->number} läuft jetzt bis {$loan->return_at->format('d.m.Y, H:i')} Uhr.");
    }

    /**
     * Cancel a loan with a reason.
     */
    public function cancel(Request $request, Loan $loan, CancelLoan $cancelLoan): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $cancelLoan->handle($loan, $validated['reason']);

        return back()->with('success', "{$loan->number} wurde storniert.");
    }

    /**
     * Close the defects of a loan with a note.
     */
    public function closeDefects(Request $request, Loan $loan, ReturnLoan $returnLoan): RedirectResponse
    {
        $validated = $request->validate(['note' => ['required', 'string', 'max:2000']]);
        $returnLoan->closeDefects($request->user(), $loan, $validated['note']);

        return back()->with('success', 'Die Mängel sind abgeschlossen.');
    }

    /**
     * Set a new pack pin: a random one, or the given six digits.
     */
    public function pin(Request $request, Loan $loan): RedirectResponse
    {
        $validated = $request->validate(['pin' => ['nullable', 'digits:6']]);
        if (! in_array($loan->status, LoanStatus::reserving(), true)) {
            throw ValidationException::withMessages(['pin' => 'Die Pack-PIN gilt nur für bestätigte und ausgegebene Buchungen.']);
        }

        $loan->forceFill(['pack_pin' => $validated['pin'] ?? ConfirmLoan::randomPin()])->save();

        return back()->with('success', 'Die Pack-PIN wurde neu gesetzt.');
    }

    /**
     * Download the booking confirmation with the pack pin.
     */
    public function confirmation(Request $request, Loan $loan, LoanDocuments $documents): HttpResponse
    {
        abort_unless($request->user()->can('loans.manage') && $loan->status !== LoanStatus::Draft && $loan->status !== LoanStatus::Cancelled, 404);

        return $this->download($documents->confirmation($loan), "Buchungsbestätigung {$loan->number}.pdf");
    }

    /**
     * Download a document: the pdf of what was signed, or the scan of a paper.
     */
    public function document(Request $request, Loan $loan, LoanDocument $document, LoanDocuments $documents): HttpResponse
    {
        abort_unless(self::canSeeDocuments($request->user()), 403);

        if ($document->type === DocumentType::PaperScan) {
            return Storage::disk('s3')->download($document->path, "Unterschriebenes Blatt {$loan->number}.".pathinfo($document->path, PATHINFO_EXTENSION));
        }

        return $this->download($documents->pdf($document), "{$document->type->label()} {$loan->number}.pdf");
    }

    /**
     * Page to add an item from the scan tab: open loans with the availability of the item.
     */
    public function addItem(Item $item, Availability $availability): Response
    {
        $loans = Loan::whereIn('status', [LoanStatus::Draft, LoanStatus::Confirmed])->where('return_at', '>', now())
            ->with(['responsible', 'responsibleUser', 'borrower'])->orderBy('pickup_at')->limit(50)->get();

        return Inertia::render('Loans::Loans/AddItem', [
            'item' => [...$item->only(['id', 'number', 'name', 'quantity', 'unit']), 'kind' => $item->kind->value],
            'problem' => $availability->problemOf($item->load('condition')),
            'loans' => $loans->map(fn (Loan $loan) => [
                ...$loan->summary(),
                'check' => $availability->check($item, 1, $loan->pickup_at, $loan->return_at, $loan),
                'contains' => $loan->loanItems()->where('item_id', $item->id)->exists(),
            ]),
        ]);
    }

    /**
     * Numbers for the dashboard tile (json).
     */
    public function stats(Request $request): JsonResponse
    {
        $today = [now()->startOfDay(), now()->endOfDay()];
        $stats = [
            'pickup_today' => Loan::where('status', LoanStatus::Confirmed)->whereBetween('pickup_at', $today)->count(),
            'return_today' => Loan::where('status', LoanStatus::Issued)->whereBetween('return_at', $today)->count(),
            'overdue' => Loan::overdue()->with('responsible', 'responsibleUser', 'borrower')->orderBy('return_at')->limit(5)->get()->map(fn (Loan $loan) => $loan->summary()),
            'overdue_count' => Loan::overdue()->count(),
        ];

        if ($request->user()->can('loans.issue')) {
            $stats['unchecked'] = Loan::where('status', LoanStatus::ReturnedUnchecked)->count();
            $stats['defects'] = Loan::where('status', LoanStatus::ClosedWithDefects)->whereNull('defects_closed_at')->count();
            $stats['not_stowed'] = StowItem::pending()->count();
        }

        return response()->json($stats);
    }

    /**
     * Form of a loan.
     *
     * @param  array<string, mixed>  $values
     */
    private function form(?Loan $loan, array $values): Response
    {
        return Inertia::render('Loans::Loans/Form', [
            'loan' => $values,
            'isNew' => $loan === null,
            'kinds' => LoanKind::options(),
            'events' => Event::options(),
            'councils' => Council::options(),
            'members' => User::where('is_disabled', false)->orderBy('firstname')->get()->map(fn (User $user) => ['value' => $user->id, 'label' => $user->name]),
        ]);
    }

    /**
     * Validated fields of the form.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'kind' => ['required', Rule::enum(LoanKind::class)],
            'borrower_contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'responsible_contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'responsible_user_id' => ['nullable', 'required_if:kind,internal', 'integer', 'exists:users,id'],
            'event_id' => ['nullable', 'integer', 'exists:events,id'],
            'pickup_at' => ['required', 'date'],
            'return_at' => ['required', 'date'],
            'purpose' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    /**
     * Positions with item data, availability, contents of boxes and the state of issue and return.
     *
     * @param  array<int, array<string, mixed>>  $checks
     * @return list<array<string, mixed>>
     */
    private function positions(Loan $loan, array $checks): array
    {
        return $loan->positions()->with(['item.condition', 'issuedItem', 'boxChecks'])->get()->map(fn (LoanItem $position) => [
            'id' => $position->id,
            'item' => [...$position->physicalItem()->only(['id', 'number']), 'name' => $position->item->name, 'unit' => $position->item->unit, 'kind' => $position->item->kind->value, 'is_container' => $position->item->is_container, 'stock' => $position->item->quantity],
            'quantity' => $position->quantity,
            'condition' => $position->condition_at_issue ?? $position->item->condition->name,
            'check' => $checks[$position->id] ?? null,
            'checked_at' => $position->checked_at?->toIso8601String(),
            'issued_at' => $position->issued_at?->toIso8601String(),
            'issue_defect' => $position->issueDefect(),
            'returned_at' => $position->returned_at?->toIso8601String(),
            'return_state' => $position->returned_at ? ($position->return_state ?? ReturnState::Ok)->label() : $position->return_state?->label(),
            'return_note' => $position->return_note,
            'stowed_at' => $position->stowed_at?->toIso8601String(),
            'box_checks' => $position->boxChecks->map(fn ($check) => ['name' => $check->name, 'checked_at' => $check->checked_at->toIso8601String(), 'ticked' => count($check->ticks), 'total' => $check->total]),
        ])->all();
    }

    /**
     * Check if a person may see the signed papers of a loan (contract, lists, receipts, scans and
     * signatures): those who issue or manage loans, not everyone who may look at loans.
     */
    public static function canSeeDocuments(User $user): bool
    {
        return $user->canAny(['loans.issue', 'loans.manage']);
    }

    /**
     * A pdf as download.
     */
    private function download(string $pdf, string $name): HttpResponse
    {
        return response($pdf, headers: [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.addcslashes($name, '"\\').'"',
        ]);
    }
}
