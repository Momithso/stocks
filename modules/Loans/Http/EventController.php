<?php

namespace Modules\Loans\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Models\Council;
use Modules\Loans\Models\Event;
use Modules\Loans\Models\Loan;
use Modules\Loans\Support\LoanDocuments;

/**
 * Events: a bracket around several loans, with a common material list.
 */
class EventController extends Controller
{
    /**
     * Display the events, coming ones first.
     */
    public function index(Request $request): Response
    {
        $past = $request->boolean('past');
        $events = Event::with('responsible')->withCount('loans')
            ->when($past, fn ($query) => $query->where('ends_at', '<', now())->orderByDesc('starts_at'), fn ($query) => $query->where('ends_at', '>=', now())->orderBy('starts_at'))
            ->limit(200)->get();

        return Inertia::render('Loans::Events/Index', [
            'events' => $events->map(fn (Event $event) => $this->summary($event)),
            'past' => $past,
            'councils' => Council::options(),
        ]);
    }

    /**
     * Create an event.
     */
    public function store(Request $request): RedirectResponse
    {
        $event = Event::create($this->validated($request));

        return to_route('loans.events.show', $event)->with('success', "{$event->name} wurde angelegt.");
    }

    /**
     * Display an event with its loans and the material list of all of them.
     */
    public function show(Request $request, Event $event, LoanDocuments $documents): Response
    {
        $event->load('responsible.council');
        $loans = $event->loans()->with('responsible', 'responsibleUser', 'borrower')->get();

        return Inertia::render('Loans::Events/Show', [
            'event' => [...$this->summary($event), 'responsible' => $event->responsible?->summary($request->user()->can('loans.manage'))],
            'loans' => $loans->map(fn (Loan $loan) => $loan->summary()),
            // what the loans of the event take along, cancelled ones left out
            'materials' => $loans->reject(fn (Loan $loan) => $loan->status === LoanStatus::Cancelled)
                ->flatMap(fn (Loan $loan) => array_map(fn (array $row) => [...$row, 'loan' => $loan->number], $documents->positions($loan)))->values(),
            'councils' => Council::options(),
        ]);
    }

    /**
     * Change an event.
     */
    public function update(Request $request, Event $event): RedirectResponse
    {
        $event->update($this->validated($request));

        return back()->with('success', "{$event->name} wurde gespeichert.");
    }

    /**
     * Delete an event without loans.
     */
    public function destroy(Event $event): RedirectResponse
    {
        if ($event->loans()->exists()) {
            throw ValidationException::withMessages(['event' => 'Die Veranstaltung hat Buchungen und bleibt gespeichert.']);
        }
        $event->delete();

        return to_route('loans.events.index')->with('success', "{$event->name} wurde gelöscht.");
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Event $event): array
    {
        return [
            ...$event->only(['id', 'name', 'place', 'notes', 'responsible_contact_id']),
            'starts_at' => $event->starts_at->toIso8601String(),
            'ends_at' => $event->ends_at->toIso8601String(),
            'responsible_name' => $event->responsible?->name,
            'loans_count' => $event->loans_count ?? $event->loans()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'place' => ['nullable', 'string', 'max:150'],
            'responsible_contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
