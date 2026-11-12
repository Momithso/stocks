<?php

namespace Modules\Loans\Http;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Loans\Models\Contact;
use Modules\Loans\Models\Council;
use Modules\Loans\Models\Event;
use Modules\Loans\Models\Loan;
use Modules\Loans\Support\ContactsTable;

/**
 * Contacts: borrowers and responsible persons. Only for members with "loans.manage", who also
 * see the contact data.
 */
class ContactController extends Controller
{
    /**
     * Display the list, also as live update.
     */
    public function index(Request $request): Response|JsonResponse
    {
        $table = new ContactsTable($request);
        if ($table->wantsChanges()) {
            return $table->changesResponse();
        }

        return Inertia::render('Loans::Contacts/Index', [
            'contacts' => $table->scroll(),
            'councils' => Council::options(),
        ]);
    }

    /**
     * Search contacts for the field of a form (json).
     */
    public function search(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search'));
        if ($search === '') {
            return response()->json([]);
        }

        return response()->json(Contact::with('council')
            ->where(fn (Builder $query) => $query->whereLike('name', "%{$search}%")->orWhereLike('email', "%{$search}%"))
            ->orderBy('name')->limit(10)->get()->map(fn (Contact $contact) => $contact->summary(true)));
    }

    /**
     * Create a contact. From the field of a form (json) the new contact comes back as json, so the
     * form can select it.
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $contact = Contact::create($this->validated($request));

        return $request->wantsJson()
            ? response()->json($contact->load('council')->summary(true), 201)
            : to_route('loans.contacts.show', $contact)->with('success', "{$contact->name} wurde angelegt.");
    }

    /**
     * Display a contact with its loans.
     */
    public function show(Contact $contact): Response
    {
        return Inertia::render('Loans::Contacts/Show', [
            'contact' => $contact->load('council')->summary(true),
            'loans' => $contact->allLoans()->with('responsible', 'responsibleUser', 'borrower')->limit(200)->get()->map(fn (Loan $loan) => $loan->summary()),
            'councils' => Council::options(),
        ]);
    }

    /**
     * Change a contact. From the field of a form (json) the contact comes back as json.
     */
    public function update(Request $request, Contact $contact): RedirectResponse|JsonResponse
    {
        $contact->update($this->validated($request));

        return $request->wantsJson()
            ? response()->json($contact->load('council')->summary(true))
            : back()->with('success', "{$contact->name} wurde gespeichert.");
    }

    /**
     * Delete a contact without loans. Retention periods follow in phase 7.
     */
    public function destroy(Contact $contact): RedirectResponse
    {
        if ($contact->allLoans()->exists() || Event::where('responsible_contact_id', $contact->id)->exists()) {
            throw ValidationException::withMessages(['contact' => "{$contact->name} hat Buchungen oder Veranstaltungen und bleibt gespeichert."]);
        }
        $contact->delete();

        return to_route('loans.contacts.index')->with('success', "{$contact->name} wurde gelöscht.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'street' => ['nullable', 'string', 'max:150'],
            'zip' => ['nullable', 'string', 'max:10'],
            'city' => ['nullable', 'string', 'max:100'],
            'council_id' => ['nullable', 'integer', 'exists:councils,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
