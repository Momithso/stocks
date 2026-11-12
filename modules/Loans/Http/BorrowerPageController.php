<?php

namespace Modules\Loans\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Loans\Actions\ExtendLoan;
use Modules\Loans\Models\Loan;
use Modules\Loans\Models\LoanExtensionRequest;
use Modules\Loans\Models\LoanMessage;
use Modules\Loans\Notifications\ExtensionDecided;
use Modules\Loans\Support\LoanNotifier;
use Modules\Loans\Support\LoanPage;
use Modules\Loans\Support\Reminders;

/**
 * What the organization does with the borrower page of a loan (loans.manage): block or open the
 * link, issue a new one, answer an extension request, send a message by hand.
 */
class BorrowerPageController extends Controller
{
    public function __construct(private readonly LoanPage $page, private readonly LoanNotifier $notifier) {}

    /**
     * Block the link, or open it again.
     */
    public function block(Request $request, Loan $loan): RedirectResponse
    {
        abort_unless(LoanPage::exists($loan), 404);
        $blocked = $request->boolean('blocked');
        $this->page->block($loan, $blocked);

        return to_route('loans.show', $loan)->with('success', $blocked ? 'Die Verleihseite ist gesperrt.' : 'Die Verleihseite ist wieder erreichbar.');
    }

    /**
     * Issue a new link; the old one and its devices with push stop at once.
     */
    public function reissue(Loan $loan): RedirectResponse
    {
        abort_unless(LoanPage::exists($loan), 404);
        $this->page->reissue($loan);

        return to_route('loans.show', $loan)->with('success', 'Die Verleihseite hat einen neuen Link. Der alte geht nicht mehr.');
    }

    /**
     * Accept an extension request: the loan gets the new return time, if everything is free until
     * then. A collision keeps the request open with the message of ExtendLoan.
     */
    public function accept(Request $request, Loan $loan, LoanExtensionRequest $extensionRequest, ExtendLoan $extendLoan): RedirectResponse
    {
        $validated = $request->validate(['answer' => ['nullable', 'string', 'max:1000']]);

        DB::transaction(function () use ($request, $loan, $extensionRequest, $extendLoan, $validated) {
            $extension = $this->lockOpen($extensionRequest);
            $extendLoan->handle($loan, $extension->requested_return_at->copy());
            $this->decide($request, $loan, $extension, LoanExtensionRequest::ACCEPTED, $validated['answer'] ?? null);
        });

        return to_route('loans.show', $loan)->with('success', "{$loan->number} läuft jetzt bis {$loan->return_at->format('d.m.Y, H:i')} Uhr.");
    }

    /**
     * Decline an extension request with an answer.
     */
    public function decline(Request $request, Loan $loan, LoanExtensionRequest $extensionRequest): RedirectResponse
    {
        $validated = $request->validate(['answer' => ['required', 'string', 'max:1000']], [], ['answer' => 'Antwort']);

        DB::transaction(fn () => $this->decide($request, $loan, $this->lockOpen($extensionRequest), LoanExtensionRequest::DECLINED, $validated['answer']));

        return to_route('loans.show', $loan)->with('success', 'Die Anfrage ist abgelehnt.');
    }

    /**
     * Send a message to the borrower by hand.
     */
    public function remind(Request $request, Loan $loan, Reminders $reminders): RedirectResponse
    {
        abort_unless(LoanPage::exists($loan), 404);
        $validated = $request->validate(['type' => ['required', Rule::in(array_keys(Reminders::MANUAL))]]);
        $reminders->sendByHand($loan, $validated['type'], $request->user());

        return to_route('loans.show', $loan)->with('success', Reminders::MANUAL[$validated['type']].' ist unterwegs.');
    }

    /**
     * Lock the request until the end of the transaction and check that it is still open, so a
     * second click finds it decided. Call it inside DB::transaction().
     *
     * @throws ValidationException
     */
    private function lockOpen(LoanExtensionRequest $extension): LoanExtensionRequest
    {
        $extension = LoanExtensionRequest::whereKey($extension->id)->lockForUpdate()->firstOrFail();
        if ($extension->status !== LoanExtensionRequest::OPEN) {
            throw ValidationException::withMessages(['return_at' => 'Über die Anfrage wurde schon entschieden.']);
        }

        return $extension;
    }

    /**
     * Store the decision, note it in the chat and tell the borrower.
     */
    private function decide(Request $request, Loan $loan, LoanExtensionRequest $extension, string $status, ?string $answer): void
    {
        $extension->forceFill(['status' => $status, 'answer' => $answer, 'decided_by' => $request->user()->id, 'decided_at' => now()])->save();
        $loan->messages()->create([
            'sender' => LoanMessage::SYSTEM,
            'author_name' => 'Stocks',
            'body' => ($status === LoanExtensionRequest::ACCEPTED ? 'Verlängerung angenommen, neue Rückgabe '.$loan->return_at->format('d.m.Y, H:i').' Uhr.' : 'Verlängerung abgelehnt.').($answer ? " {$answer}" : ''),
        ]);
        $this->notifier->toContact($loan, new ExtensionDecided($loan, $extension));
    }
}
