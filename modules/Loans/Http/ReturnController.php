<?php

namespace Modules\Loans\Http;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Inventory\ItemPhotoController;
use App\Models\ItemCondition;
use App\Models\ItemPhoto;
use App\Models\ItemUnit;
use App\Support\Surface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Loans\Actions\ReturnLoan;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Enums\ReturnState;
use Modules\Loans\Enums\SignatureRole;
use Modules\Loans\Models\Loan;
use Modules\Loans\Models\LoanItem;
use Modules\Loans\Support\RowTree;
use Modules\Loans\Support\SignatureInput;

/**
 * Return of an issued loan and the later inspection of what came back unchecked, at the PC or
 * phone. "mode" is "return" or "inspect".
 */
class ReturnController extends Controller
{
    public function __construct(private readonly ReturnLoan $returnLoan) {}

    /**
     * Display the open rows to tick off.
     */
    public function show(Loan $loan, string $mode): Response|RedirectResponse
    {
        $inspect = $mode === 'inspect';
        // nothing to take back or to inspect (any more): back to the loan, with the reason
        if ($loan->status !== ($inspect ? LoanStatus::ReturnedUnchecked : LoanStatus::Issued)) {
            $message = "{$loan->number} ist {$loan->status->label()}, ".($inspect ? 'eine Prüfung ist nicht offen.' : 'zurücknehmen geht nur, solange sie ausgegeben ist.');

            return redirect(Surface::link('loans.show', $loan) ?? Surface::route('loans.index'))->with('warning', $message);
        }
        $loan->load(['responsible', 'responsibleUser']);
        // boxes of this loan whose contents must be ticked one by one (they came back unchecked before)
        $uncheckedBoxes = LoanItem::whereIn('item_id', $loan->loanItems()->select('item_id'))
            ->whereNotNull('returned_at')->whereNull('inspected_at')
            ->whereHas('item', fn ($query) => $query->where('is_container', true))
            ->pluck('item_id')->all();

        return Inertia::render('Loans::Loans/Return', [
            'mode' => $mode,
            'loan' => [...$loan->summary(), 'internal' => $loan->isInternal(), 'status_key' => $loan->status->value],
            'rows' => RowTree::flatten($this->returnLoan->rows($loan, $inspect), fn (LoanItem $row) => [
                'checked_at' => $row->return_checked_at?->toIso8601String(),
                'checked_by_scan' => $row->return_checked_by_scan,
                'returned_quantity' => $row->returned_quantity,
                'state' => $row->return_state?->value,
                'state_label' => $row->return_state?->label(),
                'note' => $row->return_note,
                'consumable' => $row->isBulk() && ItemUnit::isConsumable($row->item->unit),
                'condition_id' => $row->physicalItem()->condition_id,
                'single_check' => $inspect || in_array($row->item_id, $uncheckedBoxes, true),
                // documented when it left, so nobody answers for it at the return
                'issue_defect' => $row->issueDefect(),
            ]),
            'returner' => $loan->responsibleName(),
            'conditions' => ItemCondition::options(),
            'states' => array_values(array_filter(ReturnState::options(), fn (array $state) => $state['value'] !== ReturnState::Ok->value)),
            'maxPhotos' => ItemPhoto::MAX_PER_ITEM,
        ]);
    }

    /**
     * Tick a row off by scan or by hand; bulk items with the counted quantity.
     */
    public function check(Request $request, Loan $loan, string $mode): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['nullable', 'required_without:row_id', 'string', 'max:200'],
            'row_id' => ['nullable', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:0'],
            'quantities' => ['array'],
            'quantities.*' => ['integer', 'min:0'],
        ]);

        $row = $this->returnLoan->check($request->user(), $loan, $mode === 'inspect', $validated['code'] ?? null, $validated['row_id'] ?? null, $validated['quantity'] ?? null, $validated['quantities'] ?? []);

        return back()->with('success', "{$row->physicalItem()->number} abgehakt.");
    }

    /**
     * Mark a row as missing, damaged or dirty.
     */
    public function defect(Request $request, Loan $loan, string $mode, LoanItem $row): RedirectResponse
    {
        abort_unless($row->loan_id === $loan->id, 404);
        $validated = $request->validate([
            'state' => ['required', Rule::enum(ReturnState::class)->except([ReturnState::Ok])],
            'note' => ['required', 'string', 'max:2000'],
            'condition_id' => ['nullable', 'required_if:state,damaged', 'integer', 'exists:item_conditions,id'],
            'photos' => ['array', 'max:'.ItemPhoto::MAX_PER_ITEM],
            'photos.*' => ItemPhotoController::fileRules(),
            'quantity' => ['nullable', 'integer', 'min:0'],
        ]);

        $this->returnLoan->defect($request->user(), $loan, $mode === 'inspect', $row, ReturnState::from($validated['state']), $validated['note'], ItemCondition::find($validated['condition_id'] ?? null), $request->file('photos', []), $validated['quantity'] ?? null);

        return back()->with('success', 'Vermerkt.');
    }

    /**
     * Take the tick and the marks of a row back.
     */
    public function reset(Loan $loan, string $mode, LoanItem $row): RedirectResponse
    {
        abort_unless($row->loan_id === $loan->id, 404);
        $this->returnLoan->reset($loan, $mode === 'inspect', $row);

        return back();
    }

    /**
     * Book what was ticked off, with receipt and signatures.
     */
    public function complete(Request $request, Loan $loan): RedirectResponse
    {
        $validated = $this->validateSignatures($request, $loan);
        $this->returnLoan->complete($request->user(), $loan, $validated['signatures'] ?? [], (bool) ($validated['on_paper'] ?? false), $validated['returner'], $request->userAgent());

        return Surface::finish(to_route('loans.show', $loan)->with('success', 'Die Rückgabe ist gebucht.'));
    }

    /**
     * Accept everything without checking it now.
     */
    public function unchecked(Request $request, Loan $loan): RedirectResponse
    {
        $validated = $this->validateSignatures($request, $loan);
        $this->returnLoan->acceptUnchecked($request->user(), $loan, $validated['signatures'] ?? [], (bool) ($validated['on_paper'] ?? false), $validated['returner'], $request->userAgent());

        return Surface::finish(to_route('loans.show', $loan)->with('success', 'Angenommen, ungeprüft. Die Prüfung steht unter Verleih › Rückgaben.'));
    }

    /**
     * Finish the inspection.
     */
    public function completeInspection(Request $request, Loan $loan): RedirectResponse
    {
        $this->returnLoan->completeInspection($request->user(), $loan);

        return Surface::finish(to_route('loans.show', $loan)->with('success', 'Die Prüfung ist abgeschlossen.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validateSignatures(Request $request, Loan $loan): array
    {
        return $request->validate([
            ...SignatureInput::rules($loan->isInternal() ? [] : [SignatureRole::Returner, SignatureRole::Receiver]),
            'returner' => ['required', 'string', 'max:150'],
        ]);
    }
}
