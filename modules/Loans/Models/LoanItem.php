<?php

namespace Modules\Loans\Models;

use App\Enums\ItemKind;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Loans\Enums\ReturnState;

/**
 * Position of a loan: an item, a box or a quantity of a bulk item. Until the issue a box stands for
 * its current contents; at the issue the contents become rows below the box (parent_id), so the
 * material list stays as it was signed even if the box is packed differently later.
 *
 * @property int $id
 * @property int $loan_id
 * @property int|null $parent_id
 * @property int $item_id
 * @property int $quantity
 * @property int|null $issued_item_id part of a bulk item that left with the loan
 * @property string|null $condition_at_issue
 * @property Carbon|null $checked_at
 * @property int|null $checked_by
 * @property bool $checked_by_scan
 * @property Carbon|null $issued_at left with the loan; null: rest that did not leave yet
 * @property int|null $issue_document_id material list it left with
 * @property ReturnState|null $issue_state defect found at the issue (damaged, dirty, incomplete)
 * @property string|null $issue_note
 * @property Carbon|null $return_checked_at ticked off at the return or the inspection, not booked yet
 * @property int|null $return_checked_by
 * @property bool $return_checked_by_scan
 * @property int|null $returned_quantity
 * @property ReturnState|null $return_state
 * @property string|null $return_note
 * @property Carbon|null $returned_at booked as back, on the receipt return_document_id
 * @property int|null $return_document_id
 * @property Carbon|null $inspected_at
 * @property int|null $inspected_by
 * @property Carbon|null $stowed_at
 * @property int|null $missing_item_id what went missing: the item, or the part split off a bulk item
 * @property string|null $missing_resolution "found" or "lost"
 * @property Carbon|null $missing_resolved_at
 */
class LoanItem extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['loan_id', 'item_id', 'quantity', 'parent_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'returned_quantity' => 'integer',
            'checked_at' => 'datetime',
            'checked_by_scan' => 'boolean',
            'issued_at' => 'datetime',
            'issue_state' => ReturnState::class,
            'return_checked_at' => 'datetime',
            'return_checked_by_scan' => 'boolean',
            'return_state' => ReturnState::class,
            'returned_at' => 'datetime',
            'inspected_at' => 'datetime',
            'stowed_at' => 'datetime',
            'missing_resolved_at' => 'datetime',
        ];
    }

    /**
     * Rows whose item is still missing ("vermisst"): not found, not written off, and the item did
     * not get a place or go back some other way (moved by hand, booked back).
     *
     * @param  Builder<LoanItem>  $query
     */
    public function scopeStillMissing(Builder $query): void
    {
        $query->whereNull('missing_resolution')
            ->whereHas('missingItem', fn (Builder $query) => $query->whereNull('retired_at')->whereNull('location_id')->whereNull('container_id'));
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function missingItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'missing_item_id');
    }

    /**
     * @return BelongsTo<Loan, $this>
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function issuedItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'issued_item_id');
    }

    /**
     * @return BelongsTo<LoanItem, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(LoanItem::class, 'parent_id');
    }

    /**
     * Frozen contents of a box.
     *
     * @return HasMany<LoanItem, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(LoanItem::class, 'parent_id')->orderBy('id');
    }

    /**
     * Checks of the box with the pack pin.
     *
     * @return HasMany<LoanBoxCheck, $this>
     */
    public function boxChecks(): HasMany
    {
        return $this->hasMany(LoanBoxCheck::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    /**
     * The item that physically left: the split off part of a bulk item, or the item itself.
     */
    public function physicalItem(): Item
    {
        return $this->issuedItem ?? $this->item;
    }

    /**
     * Check if the position is a part of a bulk item.
     */
    public function isBulk(): bool
    {
        return $this->item->kind === ItemKind::Bulk;
    }

    /**
     * Ids of all rows below this one (the contents of a box, boxes in it included).
     *
     * @return list<int>
     */
    public function descendantIds(): array
    {
        $ids = [];
        $level = [$this->id];
        // the limit only protects against broken data
        for ($depth = 0; $level !== [] && $depth < 20; $depth++) {
            $level = self::whereIn('parent_id', $level)->pluck('id')->all();
            $ids = [...$ids, ...$level];
        }

        return $ids;
    }

    /**
     * Check if the row is a box that came back unchecked with another loan and was not inspected
     * yet: its contents must be ticked off one by one.
     */
    public function isUncheckedBox(): bool
    {
        return $this->item->is_container
            && self::where('item_id', $this->item_id)->whereKeyNot($this->id)->whereNotNull('returned_at')->whereNull('inspected_at')->exists();
    }

    /**
     * Defect found at the issue as one line, e.g. "beschädigt: Kratzer am Gehäuse".
     */
    public function issueDefect(): ?string
    {
        return $this->issue_state ? $this->issue_state->label().($this->issue_note ? ": {$this->issue_note}" : '') : null;
    }

    /**
     * Check if the position was dealt with at the return: ticked off or marked as defect.
     */
    public function isReturnHandled(): bool
    {
        return $this->return_checked_at !== null || $this->return_state !== null;
    }
}
