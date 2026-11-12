<?php

namespace Modules\Loans\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Loans\Models\Loan;
use Modules\Loans\Models\LoanMessage;
use Modules\Loans\Support\LoanNotifier;
use Modules\Loans\Support\LoanPage;
use Modules\Loans\Support\LoanPageData;

/**
 * The chat of a loan on the side of the organization (card "Chat" of the loan page), for members
 * with "loans.manage". The borrower writes on the borrower page (LoanPageController).
 */
class ChatController extends Controller
{
    public function __construct(private readonly LoanPageData $data, private readonly LoanNotifier $notifier) {}

    /**
     * Messages after an id (json); "read" marks those of the borrower as read.
     */
    public function index(Request $request, Loan $loan): JsonResponse
    {
        if ($request->boolean('read')) {
            $this->data->markRead($loan, 'staff');
        }

        return response()->json(['messages' => $this->data->messages($loan, $request->integer('after'), 'staff', $request->user()->id)]);
    }

    /**
     * Send a message as member. The borrower gets it by mail or push, by the settings of the page.
     */
    public function store(Request $request, Loan $loan): JsonResponse
    {
        abort_unless(LoanPage::exists($loan), 404);
        $validated = $request->validate(['body' => ['required', 'string', 'max:'.LoanMessage::MAX_LENGTH]], [], ['body' => 'Nachricht']);

        $message = $loan->messages()->create([
            'sender' => LoanMessage::USER,
            'user_id' => $request->user()->id,
            'author_name' => $request->user()->name,
            'body' => trim($validated['body']),
        ]);
        // answering means having read
        $this->data->markRead($loan, 'staff');
        $this->notifier->chat($loan, $message);

        return response()->json($message->summary('staff', $request->user()->id));
    }
}
