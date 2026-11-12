<?php

namespace Modules\Loans\Http;

use App\Http\Controllers\Controller;
use App\Http\Controllers\WebAppController;
use App\Support\Branding;
use App\Support\Notifications\NotificationEvents;
use App\Support\Notifications\WebPush;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Loans\Enums\DocumentType;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Models\Loan;
use Modules\Loans\Models\LoanExtensionRequest;
use Modules\Loans\Models\LoanItem;
use Modules\Loans\Models\LoanMessage;
use Modules\Loans\Notifications\ExtensionRequested;
use Modules\Loans\Support\LoanDocuments;
use Modules\Loans\Support\LoanNotifier;
use Modules\Loans\Support\LoanPage;
use Modules\Loans\Support\LoanPageData;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The borrower page without login (/v/{loan}/{token}, middleware "loan.page"): overview, material
 * with checklist, chat, extension request, pack pin, documents, notifications and push. See
 * documentation/LOANS.md.
 */
class LoanPageController extends Controller
{
    public function __construct(private readonly LoanPageData $data, private readonly LoanNotifier $notifier) {}

    /**
     * Display the page; "?tab=chat" opens the chat.
     */
    public function show(Request $request, Loan $loan): Response
    {
        $tab = in_array($request->query('tab'), ['chat', 'overview', 'material'], true) ? $request->query('tab') : 'overview';
        if ($tab === 'chat') {
            $this->data->markRead($loan, 'contact');
        }

        return Inertia::render('Loans::Page/Show', [...$this->data->page($loan), 'tab' => $tab])
            ->withViewData(['manifest' => LoanPage::path($loan).'/manifest.webmanifest']);
    }

    /**
     * Live update (json): state, new messages after an id, ticks of the checklist. "read" marks the
     * messages as read while the chat is open.
     */
    public function poll(Request $request, Loan $loan): JsonResponse
    {
        if ($request->boolean('read')) {
            $this->data->markRead($loan, 'contact');
        }

        return response()->json([
            'loan' => $this->data->state($loan),
            'messages' => $this->data->messages($loan, $request->integer('after')),
            'rows' => $this->data->rows($loan),
        ]);
    }

    /**
     * Send a message as borrower.
     */
    public function message(Request $request, Loan $loan): JsonResponse
    {
        $validated = $request->validate(['body' => ['required', 'string', 'max:'.LoanMessage::MAX_LENGTH]], [], ['body' => 'Nachricht']);
        if ($loan->status === LoanStatus::Cancelled) {
            throw ValidationException::withMessages(['body' => 'Die Ausleihe ist storniert. Schreib uns bitte per E-Mail.']);
        }
        $loan->loadMissing('responsible');

        $message = $loan->messages()->create([
            'sender' => LoanMessage::CONTACT,
            'author_name' => 'Entleiher'.($loan->responsible ? " ({$loan->responsible->name})" : ''),
            'body' => trim($validated['body']),
        ]);
        $message->forceFill(['read_by_contact_at' => now()])->save();
        LoanNotifier::clearUnread();
        $this->notifier->chat($loan, $message);

        return response()->json($message->summary('contact'));
    }

    /**
     * Tick a row of the checklist off or take it back; "reset" takes back all. Only while the loan
     * is out. A box ticks its contents along.
     */
    public function tick(Request $request, Loan $loan): JsonResponse
    {
        $validated = $request->validate(['row' => ['nullable', 'integer'], 'checked' => ['boolean'], 'reset' => ['boolean']]);
        if ($loan->status !== LoanStatus::Issued) {
            throw ValidationException::withMessages(['row' => 'Abhaken geht, solange das Material ausgeliehen ist.']);
        }

        if ($request->boolean('reset')) {
            $loan->loanItems()->update(['borrower_checked_at' => null]);
        } else {
            $row = $loan->loanItems()->whereNotNull('issued_at')->findOrFail($validated['row'] ?? 0);
            $checked = $request->boolean('checked');
            $ids = [$row->id, ...$row->descendantIds()];
            // taking a row back also takes back the boxes it is in
            if (! $checked) {
                for ($parent = $row->parent; $parent !== null; $parent = $parent->parent) {
                    $ids[] = $parent->id;
                }
            }
            LoanItem::whereIn('id', $ids)->where('loan_id', $loan->id)->update(['borrower_checked_at' => $checked ? now() : null]);
        }

        return response()->json(['rows' => $this->data->rows($loan)]);
    }

    /**
     * Ask for a later return time. One open request per loan; the members involved get a message.
     */
    public function extension(Request $request, Loan $loan): RedirectResponse
    {
        $validated = $request->validate([
            'return_at' => ['required', 'date', 'after:now'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [], ['return_at' => 'gewünschte Rückgabe', 'note' => 'Notiz']);
        $returnAt = Carbon::parse($validated['return_at']);

        DB::transaction(function () use ($loan, $validated, $returnAt) {
            $loan->lockForTask();
            if ($loan->status !== LoanStatus::Issued) {
                throw ValidationException::withMessages(['return_at' => 'Verlängern geht nur, solange das Material ausgeliehen ist.']);
            }
            if ($loan->extensionRequests()->where('status', LoanExtensionRequest::OPEN)->exists()) {
                throw ValidationException::withMessages(['return_at' => 'Es gibt schon eine offene Anfrage.']);
            }
            if ($returnAt->lte($loan->return_at)) {
                throw ValidationException::withMessages(['return_at' => 'Der Wunschtermin muss nach der jetzigen Rückgabe liegen.']);
            }

            $extension = $loan->extensionRequests()->create(['requested_return_at' => $returnAt, 'note' => $validated['note'] ?? null]);
            $loan->messages()->create([
                'sender' => LoanMessage::SYSTEM,
                'author_name' => 'Stocks',
                'body' => 'Verlängerung bis '.$returnAt->format('d.m.Y, H:i').' Uhr angefragt.'.($extension->note ? " Notiz: {$extension->note}" : ''),
            ]);
            LoanNotifier::clearUnread();
            $this->notifier->toStaff($loan, new ExtensionRequested($loan, $extension));
        });

        return redirect(LoanPage::url($loan))->with('success', 'Die Anfrage ist beim FSR. Du bekommst Bescheid, sobald jemand entschieden hat.');
    }

    /**
     * Change the pack pin (six digits), while it counts.
     */
    public function pin(Request $request, Loan $loan): RedirectResponse
    {
        $validated = $request->validate(['pin' => ['required', 'digits:6']], [], ['pin' => 'Pack-PIN']);
        if (! in_array($loan->status, LoanStatus::reserving(), true)) {
            throw ValidationException::withMessages(['pin' => 'Die Pack-PIN gilt nur bis zur Rückgabe.']);
        }
        $loan->forceFill(['pack_pin' => $validated['pin']])->save();

        return redirect(LoanPage::url($loan))->with('success', 'Die Pack-PIN wurde geändert.');
    }

    /**
     * Download a document of this loan (not the scan of a paper).
     */
    public function document(Loan $loan, int $document, LoanDocuments $documents): HttpResponse
    {
        $document = $loan->documents()->where('type', '!=', DocumentType::PaperScan)->findOrFail($document);

        return response($documents->pdf($document), headers: [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.addcslashes("{$document->type->label()} {$loan->number}.pdf", '"\\').'"',
        ]);
    }

    /**
     * Manifest of the page as a web app of its own: added to the home screen it starts with this
     * loan and gets push there (iPhone).
     */
    public function manifest(Loan $loan, Settings $settings, Branding $branding): JsonResponse
    {
        $path = LoanPage::path($loan);
        $version = pathinfo((string) $branding->path('logo'), PATHINFO_FILENAME) ?: 'default';

        return response()->json([
            'id' => $path,
            'name' => "Ausleihe {$loan->number}",
            'short_name' => $loan->number,
            'start_url' => $path,
            'scope' => $path,
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => $settings->get('organization.accent_color'),
            'icons' => array_map(fn (int $size) => [
                'src' => route('web-app.icon', ['size' => $size, 'v' => $version], absolute: false),
                'sizes' => "{$size}x{$size}",
                'type' => 'image/png',
                'purpose' => 'any',
            ], WebAppController::ICON_SIZES),
        ], headers: ['Content-Type' => 'application/manifest+json']);
    }

    /**
     * Store the push subscription of this device for the page.
     */
    public function subscribe(Request $request, Loan $loan, WebPush $webPush): JsonResponse
    {
        return response()->json($webPush->subscribe($loan, $request)->summary());
    }

    /**
     * Remove the push subscription of this device.
     */
    public function unsubscribe(Request $request, Loan $loan): JsonResponse
    {
        $loan->deletePushSubscription((string) $request->input('endpoint'));

        return response()->json(['ok' => true]);
    }

    /**
     * Save the notification settings of the page; they count for everyone with the link.
     */
    public function notifications(Request $request, Loan $loan, NotificationEvents $events): RedirectResponse
    {
        $validated = $request->validate(['events' => ['array'], 'events.*' => ['array'], 'events.*.*' => ['boolean']]);
        $loan->forceFill(['notification_preferences' => $events->deviations(NotificationEvents::CONTACTS, $validated['events'] ?? [])])->save();

        return redirect(LoanPage::url($loan))->with('success', 'Die Benachrichtigungen wurden gespeichert.');
    }
}
