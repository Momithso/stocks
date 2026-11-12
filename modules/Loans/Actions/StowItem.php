<?php

namespace Modules\Loans\Actions;

use App\Actions\Inventory\MergeItem;
use App\Actions\Inventory\MoveItem;
use App\Actions\Inventory\RetireItem;
use App\Enums\RetireReason;
use App\Models\Item;
use App\Models\Location;
use App\Models\User;
use App\Support\Inventory\CodeLookup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Enums\ReturnState;
use Modules\Loans\Models\LoanItem;

/**
 * Put an item back after the return ("Einräumen"). Stocks shows the home; the scan of the shelf
 * confirms it and moves the item there. A split off part of a bulk item goes back to its origin
 * instead (MergeItem). Until then the item is "angenommen, noch nicht eingeräumt".
 */
class StowItem
{
    public function __construct(
        private readonly CodeLookup $codes,
        private readonly MoveItem $moveItem,
        private readonly MergeItem $mergeItem,
        private readonly RetireItem $retireItem,
    ) {}

    /**
     * Positions that came back and wait to be put back. Contents of boxes travel with their box; an
     * item that got a place some other way counts as put back. Something missing that turned up
     * again (ResolveMissing) waits here too, also from a box.
     *
     * @return Builder<LoanItem>
     */
    public static function pending(): Builder
    {
        return LoanItem::query()
            ->whereNotNull('returned_at')
            ->whereNull('stowed_at')
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->whereNull('parent_id')->where(fn (Builder $query) => $query->whereNull('return_state')->orWhere('return_state', '!=', ReturnState::Missing)->orWhere('returned_quantity', '>', 0)))
                ->orWhere('missing_resolution', 'found'))
            ->whereHas('loan', fn (Builder $query) => $query->where('status', '!=', LoanStatus::Cancelled))
            ->where(fn (Builder $query) => $query
                ->whereHas('issuedItem', fn (Builder $query) => $query->whereNull('retired_at'))
                ->orWhere(fn (Builder $query) => $query->whereNull('issued_item_id')->whereHas('item', fn (Builder $query) => $query->whereNull('location_id')->whereNull('container_id'))));
    }

    /**
     * Put the item of a position at the scanned place (a split off part goes to its origin, the
     * code is not needed then).
     *
     * @throws ValidationException
     */
    public function handle(User $actor, LoanItem $row, ?string $code): string
    {
        return DB::transaction(function () use ($actor, $row, $code) {
            // locked and checked again, so a second tap does not put it back twice
            LoanItem::whereKey($row->id)->lockForUpdate()->first();
            if (! self::pending()->whereKey($row->id)->exists()) {
                throw ValidationException::withMessages(['code' => 'Die Position wartet nicht aufs Einräumen.']);
            }
            $row->refresh()->load(['item', 'issuedItem', 'loan']);

            $returned = $row->returned_quantity ?? $row->quantity;

            // a split off part: the quantity that came back goes to the origin
            if ($row->issuedItem !== null) {
                $origin = $this->mergeItem->handle($actor, $row->issuedItem, min($returned, $row->issuedItem->quantity), "Rückgabe {$row->loan->number}.");
                $row->forceFill(['stowed_at' => now()])->save();

                return "{$returned} {$origin->unit} zurück zu {$origin->number}.";
            }

            $place = $this->codes->findPlace((string) $code)
                ?? throw ValidationException::withMessages(['code' => $this->codes->notFoundMessage((string) $code, place: true)]);
            $item = $row->item;

            // a whole bulk item that came back incomplete: the rest is lost
            $short = $row->isBulk() ? $item->quantity - $returned : 0;
            if ($short > 0) {
                $this->retireItem->handle($actor, $item, RetireReason::Lost, "Fehlt nach {$row->loan->number}.", $short);
            }

            $this->moveItem->handle($actor, $item, $place, note: "Eingeräumt nach {$row->loan->number}.");
            $row->forceFill(['stowed_at' => now()])->save();

            return $item->home() !== null && ! $item->isAwayFromHome()
                ? "{$item->number} liegt wieder an seinem Stammplatz."
                : "{$item->number} liegt jetzt in {$this->codeOf($place)}, nicht am Stammplatz.";
        });
    }

    /**
     * Code of a place for messages.
     */
    private function codeOf(Location|Item $place): string
    {
        return $place instanceof Location ? $place->full_code : $place->number;
    }
}
