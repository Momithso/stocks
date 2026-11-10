<?php

namespace App\Support\Table;

use App\Models\Category;
use App\Models\Item;
use App\Models\ItemCondition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Items of the inventory. Filters: category (with its sub categories), condition, retired, without
 * printed label ("noch ohne Etikett").
 */
class ItemsTable extends Table
{
    /**
     * @param  bool  $onlyMisplaced  only items without place or away from their home (page "Ohne Platz")
     */
    public function __construct(Request $request, private readonly bool $onlyMisplaced = false)
    {
        parent::__construct($request);
    }

    /**
     * {@inheritDoc}
     */
    protected function query(): Builder
    {
        $query = Item::query()->with(['category.parent', 'condition', 'location', 'container', 'photos']);

        return $this->onlyMisplaced ? $query->active()->misplaced() : $query;
    }

    /**
     * {@inheritDoc}
     */
    protected function applyFilters(Builder $query): void
    {
        if ($this->request->boolean('retired')) {
            $query->whereNotNull('retired_at');
        } else {
            $query->whereNull('retired_at');
        }

        if ($categoryId = $this->request->integer('category')) {
            $ids = Category::where('parent_id', $categoryId)->pluck('id')->push($categoryId);
            $query->whereIn('category_id', $ids);
        }

        if ($conditionId = $this->request->integer('condition')) {
            $query->where('condition_id', $conditionId);
        }

        if ($this->request->boolean('unlabeled')) {
            $query->whereNull('label_printed_at');
        }
    }

    /**
     * {@inheritDoc}
     *
     * @param  Item  $model
     */
    protected function row(Model $model): array
    {
        return [
            'id' => $model->id,
            'number' => $model->number,
            'name' => $model->name,
            'category' => $model->category?->fullName(),
            'quantity' => $model->quantity,
            'unit' => $model->unit,
            'kind' => $model->kind->value,
            'is_container' => $model->is_container,
            'condition' => $model->condition->name,
            'condition_is_defect' => $model->condition->is_defect,
            'place' => Item::placeSummary($model->place()),
            'away_from_home' => $model->isAwayFromHome(),
            'retired' => $model->isRetired(),
            'photo_url' => $model->photos->first()?->url(),
            'label_printed_at' => $model->label_printed_at?->toIso8601String(),
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function searchColumns(): array
    {
        return ['number', 'name', 'description', 'category.name', 'location.full_code', 'container.number'];
    }

    /**
     * {@inheritDoc}
     */
    protected function sorts(): array
    {
        return [
            'number' => 'items.number',
            'name' => 'items.name',
            'condition' => fn (Builder $query, string $direction) => $query->orderBy(
                ItemCondition::select('name')->whereColumn('item_conditions.id', 'items.condition_id'),
                $direction,
            ),
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function applyDefaultSort(Builder $query): void
    {
        $query->orderBy('items.name');
    }
}
