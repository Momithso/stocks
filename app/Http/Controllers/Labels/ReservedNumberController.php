<?php

namespace App\Http\Controllers\Labels;

use App\Actions\Labels\ReserveNumbers;
use App\Http\Controllers\Controller;
use App\Models\ReservedNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stock labels: reserve numbers and print them before the items exist. Scanning such a number
 * opens the form "Gegenstand anlegen" with exactly this number.
 */
class ReservedNumberController extends Controller
{
    /**
     * Display the open reservations.
     */
    public function index(): Response
    {
        return Inertia::render('Labels/Reserved', [
            'reserved' => ReservedNumber::open()->with('creator')->latest('id')->limit(500)->get()->map(fn (ReservedNumber $reserved) => [
                'id' => $reserved->id,
                'number' => $reserved->number,
                'created_at' => $reserved->created_at->toIso8601String(),
                'created_by' => $reserved->creator?->name,
            ]),
            'max' => ReserveNumbers::MAX,
        ]);
    }

    /**
     * Reserve numbers and open the print preview with them.
     */
    public function store(Request $request, ReserveNumbers $reserveNumbers): RedirectResponse
    {
        $validated = $request->validate(['count' => ['required', 'integer', 'between:1,'.ReserveNumbers::MAX]]);

        $reserved = $reserveNumbers->handle($request->user(), $validated['count']);

        return to_route('labels.print', ['reserved' => $reserved->pluck('id')->implode(',')]);
    }

    /**
     * Release reservations that were not used. The numbers are never given out again.
     */
    public function release(Request $request): RedirectResponse
    {
        $validated = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);

        $count = ReservedNumber::open()->whereIn('id', $validated['ids'])->update(['released_at' => now()]);

        return back()->with('success', $count === 1 ? 'Eine Reservierung wurde freigegeben.' : "{$count} Reservierungen wurden freigegeben.");
    }
}
