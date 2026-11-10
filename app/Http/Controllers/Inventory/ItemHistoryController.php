<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\ItemConditionChange;
use App\Models\ItemMovement;
use App\Models\ItemPhoto;
use App\Support\Audit\AuditRecorder;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;

/**
 * Delete wrong entries from the history of an item, e.g. a condition change whose photos could
 * not be stored. Only the entry goes away: place and condition of the item stay as they are.
 * The audit log of the item keeps what was deleted.
 */
class ItemHistoryController extends Controller
{
    /**
     * Delete a row of the movement log.
     */
    public function destroyMovement(Item $item, ItemMovement $movement): RedirectResponse
    {
        $movement->delete();

        $places = collect([$movement->fromLocation ?? $movement->fromContainer, $movement->toLocation ?? $movement->toContainer])
            ->map(fn ($place) => Item::placeSummary($place)['code'] ?? 'nirgends')
            ->implode(' → ');
        $this->audit($item, $movement->type->label(), $movement->created_at, "{$places} {$movement->note}");

        return back()->with('success', 'Der Eintrag wurde aus dem Verlauf gelöscht.');
    }

    /**
     * Delete a condition change with its photos.
     */
    public function destroyConditionChange(Item $item, ItemConditionChange $conditionChange): RedirectResponse
    {
        $paths = $conditionChange->photos()->pluck('path')->all();

        // the photo rows are deleted by the database (cascade)
        $conditionChange->delete();

        $this->audit($item, "Zustand {$conditionChange->from_condition} → {$conditionChange->to_condition}", $conditionChange->created_at, $conditionChange->reason);

        // files that S3 can not reach or never got are skipped, the entry is gone anyway
        if ($paths) {
            Storage::disk(ItemPhoto::DISK)->delete($paths);
        }

        return back()->with('success', 'Der Eintrag wurde aus dem Verlauf gelöscht.');
    }

    /**
     * Record the deleted entry in the audit log of the item.
     */
    private function audit(Item $item, string $title, CarbonInterface $at, ?string $text): void
    {
        AuditRecorder::record($item, 'historyEntryDeleted', [
            'history' => trim(ucfirst($title).' vom '.$at->format('d.m.Y').': '.trim((string) $text), ': '),
        ]);
    }
}
