<?php

namespace Modules\Loans\Http;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Inventory\ItemPhotoController;
use App\Models\ItemCondition;
use App\Models\ItemPhoto;
use App\Support\Surface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Loans\Actions\IssueLoan;
use Modules\Loans\Enums\DocumentType;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Enums\ReturnState;
use Modules\Loans\Enums\SignatureRole;
use Modules\Loans\Models\Contact;
use Modules\Loans\Models\Council;
use Modules\Loans\Models\Loan;
use Modules\Loans\Models\LoanBoxCheck;
use Modules\Loans\Models\LoanItem;
use Modules\Loans\Support\Availability;
use Modules\Loans\Support\LoanDocuments;
use Modules\Loans\Support\RowTree;
use Modules\Loans\Support\SignatureInput;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The issue of a confirmed loan at the PC or phone: scan and tick off, documents, signatures.
 */
class IssueController extends Controller
{
    public function __construct(private readonly IssueLoan $issueLoan) {}

    /**
     * Display the issue. The contents of the boxes are brought up to date first.
     */
    public function show(Request $request, Loan $loan, LoanDocuments $documents, Availability $availability): Response
    {
        $this->issueLoan->prepare($loan);
        $loan->load(['responsible.council', 'borrower', 'event']);
        // what left before (partial issue) is not part of the page
        $rows = $loan->loanItems()->whereNull('issued_at')->with(['item', 'issuedItem', 'boxChecks'])->get();
        $snapshot = $documents->issueSnapshot($loan, $request->user());

        return Inertia::render('Loans::Loans/Issue', [
            'loan' => [
                ...$loan->summary(),
                'internal' => $loan->isInternal(),
                'responsible' => $loan->responsible?->summary(true),
                'borrower' => $loan->borrower?->name,
                // the rest of a partial issue leaves with a material list of its own
                'supplement' => $loan->status === LoanStatus::Issued,
                // the return time is over: nothing leaves any more (IssueLoan::ensureIssuable())
                'expired' => $loan->return_at->isPast(),
            ],
            'rows' => RowTree::flatten($rows, fn (LoanItem $row) => [
                'checked_at' => $row->checked_at?->toIso8601String(),
                'checked_by_scan' => $row->checked_by_scan,
                'stock' => $row->item->quantity,
                'box_checks' => $row->boxChecks->map(fn (LoanBoxCheck $check) => "kontrolliert von {$check->name} am {$check->checked_at->format('d.m. H:i')} (".count($check->ticks)." von {$check->total})")->all(),
                'hints' => $row->parent_id === null && $row->item->is_container ? $availability->check($row->item, 1, $loan->pickup_at, $loan->return_at, $loan)['hints'] : [],
                'issue_state' => $row->issue_state?->value,
                'issue_defect' => $row->issueDefect(),
            ]),
            'states' => array_values(array_filter(ReturnState::options(), fn (array $state) => in_array($state['value'], [ReturnState::Damaged->value, ReturnState::Dirty->value, ReturnState::Incomplete->value], true))),
            'conditions' => ItemCondition::options(),
            'maxPhotos' => ItemPhoto::MAX_PER_ITEM,
            'contract' => $snapshot['contract'],
            'issuer' => $request->user()->name,
            'councils' => Council::options(),
            // a scanned item that is not in the loan, offered to be added (set by check())
            'foreign' => session('foreign'),
        ]);
    }

    /**
     * Tick a row off by scan (code) or by hand (row id).
     */
    public function check(Request $request, Loan $loan, Availability $availability): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['nullable', 'required_without:row_id', 'string', 'max:200'],
            'row_id' => ['nullable', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1'],
        ]);

        $result = $this->issueLoan->check($request->user(), $loan, $validated['code'] ?? null, $validated['row_id'] ?? null, $validated['quantity'] ?? null);
        if ($foreign = $result['foreign']) {
            $check = $availability->check($foreign->load('condition'), 1, $loan->pickup_at, $loan->return_at, $loan);

            return back()->with('foreign', [
                ...$foreign->only(['id', 'number', 'name', 'quantity', 'unit']),
                'kind' => $foreign->kind->value,
                'free' => ! $check['blocked'],
                'message' => $check['blocked'] ? $availability->message($foreign, $check) : "{$foreign->number} {$foreign->name} gehört nicht zur Buchung, ist aber frei.",
            ]);
        }

        return back()->with('success', "{$result['row']->physicalItem()->number} abgehakt.");
    }

    /**
     * Take the tick of a row back.
     */
    public function uncheck(Loan $loan, LoanItem $row): RedirectResponse
    {
        abort_unless($row->loan_id === $loan->id, 404);
        $this->issueLoan->uncheck($loan, $row);

        return back();
    }

    /**
     * Change the responsible person right before the signature.
     */
    public function responsible(Request $request, Loan $loan): RedirectResponse
    {
        $validated = $request->validate(['responsible_contact_id' => ['required', 'integer', 'exists:contacts,id']]);
        $this->issueLoan->changeResponsible($loan, Contact::findOrFail($validated['responsible_contact_id']));

        return back()->with('success', 'Die verantwortliche Person wurde geändert.');
    }

    /**
     * Finish the issue with the signatures or the paper.
     */
    public function complete(Request $request, Loan $loan): RedirectResponse
    {
        $validated = $request->validate([
            ...SignatureInput::rules($loan->isInternal() ? [] : [SignatureRole::Responsible, SignatureRole::Issuer]),
            'scan' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ]);
        $scan = $request->file('scan');

        $this->issueLoan->complete($request->user(), $loan, $validated['signatures'] ?? [], (bool) ($validated['on_paper'] ?? false), $scan instanceof UploadedFile ? $scan : null, $request->userAgent());

        $rest = IssueLoan::hasRest($loan->refresh());

        return Surface::finish(to_route('loans.show', $loan)->with('success', $rest ? "{$loan->number} ist zum Teil ausgegeben, der Rest folgt später." : "{$loan->number} ist ausgegeben."));
    }

    /**
     * Document a defect found at the issue; "keep_back" keeps the position here as rest.
     */
    public function defect(Request $request, Loan $loan, LoanItem $row): RedirectResponse
    {
        abort_unless($row->loan_id === $loan->id, 404);
        $validated = $request->validate([
            'state' => ['required', Rule::in([ReturnState::Damaged->value, ReturnState::Dirty->value, ReturnState::Incomplete->value])],
            'note' => ['required', 'string', 'max:2000'],
            'condition_id' => ['nullable', 'required_if:state,damaged', 'integer', 'exists:item_conditions,id'],
            'photos' => ['array', 'max:'.ItemPhoto::MAX_PER_ITEM],
            'photos.*' => ItemPhotoController::fileRules(),
            'keep_back' => ['boolean'],
        ]);

        $this->issueLoan->defect($request->user(), $loan, $row, ReturnState::from($validated['state']), $validated['note'], ItemCondition::find($validated['condition_id'] ?? null), $request->file('photos', []), (bool) ($validated['keep_back'] ?? false));

        return back()->with('success', 'Mangel vermerkt.');
    }

    /**
     * Take the defect mark of a row back.
     */
    public function resetDefect(Loan $loan, LoanItem $row): RedirectResponse
    {
        abort_unless($row->loan_id === $loan->id, 404);
        $this->issueLoan->resetDefect($loan, $row);

        return back();
    }

    /**
     * Drop a rest from the loan.
     */
    public function drop(Loan $loan, LoanItem $row): RedirectResponse
    {
        abort_unless($row->loan_id === $loan->id, 404);
        $this->issueLoan->drop($loan, $row);

        return back()->with('success', "{$row->item->number} ist nicht mehr in der Buchung.");
    }

    /**
     * Contract or material list before the issue, without signatures: to read, or to print for
     * signing on paper.
     */
    public function preview(Request $request, Loan $loan, string $type, LoanDocuments $documents): HttpResponse
    {
        $documentType = $type === 'contract' ? DocumentType::Contract : DocumentType::MaterialList;
        abort_if($documentType === DocumentType::Contract && $loan->isInternal(), 404);

        return response($documents->preview($loan, $documentType, $request->user()), headers: [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$documentType->label().' '.$loan->number.'.pdf"',
        ]);
    }
}
