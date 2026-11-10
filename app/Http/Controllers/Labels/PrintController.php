<?php

namespace App\Http\Controllers\Labels;

use App\Enums\LabelTarget;
use App\Enums\LocationType;
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\LabelTemplate;
use App\Models\Location;
use App\Models\Printer;
use App\Models\ReservedNumber;
use App\Support\Branding;
use App\Support\Labels\LabelData;
use App\Support\Labels\ShortLink;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Printing labels: always through the preview with the real data, then to the chosen printer.
 * One page for a single label and for batches (selection of the list, contents of a box, all
 * shelves and compartments below a location, stock labels).
 */
class PrintController extends Controller
{
    /**
     * Most labels in one batch.
     */
    public const MAX_LABELS = 500;

    /**
     * Display the print preview. Query: items, locations, reserved (lists of ids separated by
     * commas), box (id: its whole contents), below (id of a location: shelves and compartments).
     */
    public function create(Request $request, LabelData $labels, ShortLink $links, Branding $branding, Settings $settings): Response
    {
        return Inertia::render('Labels/Print', [
            'labels' => fn () => $this->labels($request, $labels)->take(self::MAX_LABELS)->values(),
            'templates' => LabelTemplate::orderBy('name')->get()->map(fn (LabelTemplate $template) => $template->summary()),
            'printers' => Printer::with('printerModel')->orderBy('name')->get()->map(fn (Printer $printer) => $printer->summary()),
            'variables' => collect(LabelTarget::cases())->mapWithKeys(fn (LabelTarget $target) => [$target->value => $target->variables()]),
            'baseUrl' => $links->baseUrl(),
            'baseUrlConfirmed' => $links->isConfirmed(),
            // a separate logo for print can replace the screen logo (e.g. a dark one instead of a white one)
            'logoUrl' => $branding->url($branding->printKind()),
            'back' => $request->headers->get('referer'),
        ]);
    }

    /**
     * Remember when labels were printed (also a downloaded image counts). No audit entry.
     */
    public function printed(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'items' => ['array'],
            'items.*' => ['integer'],
            'locations' => ['array'],
            'locations.*' => ['integer'],
        ]);

        // query updates, so neither the audit nor updated_at change
        Item::whereIn('id', $validated['items'] ?? [])->toBase()->update(['label_printed_at' => now()]);
        Location::whereIn('id', $validated['locations'] ?? [])->toBase()->update(['label_printed_at' => now()]);

        return back();
    }

    /**
     * Confirm the address in the codes before the first print. Asked again when it changes.
     */
    public function confirmAddress(ShortLink $links, Settings $settings): RedirectResponse
    {
        $settings->set(['labels.base_url_confirmed' => $links->baseUrl()]);

        return back();
    }

    /**
     * Labels of the query, in the order items, box contents, locations, reserved numbers.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function labels(Request $request, LabelData $labels): Collection
    {
        $ids = fn (string $key) => array_filter(array_map('intval', explode(',', (string) $request->query($key))));

        $items = Item::active()->whereIn('id', $ids('items'))->orderBy('name')->get();
        if ($box = Item::find($request->integer('box'))) {
            $items = $items->concat($this->contentsOf($box));
        }

        $locations = Location::whereIn('id', $ids('locations'))->orderBy('full_code')->get();
        if ($parent = Location::find($request->integer('below'))) {
            $locations = $locations->concat(
                Location::whereIn('id', $parent->subtreeIds())
                    ->whereIn('type', [LocationType::Shelf, LocationType::Compartment])
                    ->orderBy('full_code')->get(),
            );
        }

        return $items->unique('id')->map(fn (Item $item) => $labels->forItem($item))
            ->concat($locations->unique('id')->map(fn (Location $location) => $labels->forLocation($location)))
            ->concat(ReservedNumber::open()->whereIn('id', $ids('reserved'))->orderBy('number')->get()
                ->map(fn (ReservedNumber $reserved) => $labels->forReserved($reserved)));
    }

    /**
     * Active items in a box, boxes in boxes included.
     *
     * @return Collection<int, Item>
     */
    private function contentsOf(Item $box, int $depth = 0): Collection
    {
        // the limit only protects against broken data
        if ($depth > 20) {
            return collect();
        }

        return $box->contents()->active()->get()
            ->flatMap(fn (Item $item) => [$item, ...($item->is_container ? $this->contentsOf($item, $depth + 1) : [])]);
    }
}
