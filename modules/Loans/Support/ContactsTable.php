<?php

namespace Modules\Loans\Support;

use App\Support\Table\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Loans\Models\Contact;

/**
 * List of contacts with their council and the number of loans.
 */
class ContactsTable extends Table
{
    /**
     * {@inheritDoc}
     */
    protected function query(): Builder
    {
        return Contact::query()->with('council')->withCount('loans');
    }

    /**
     * {@inheritDoc}
     */
    protected function applyFilters(Builder $query): void
    {
        if ($councilId = $this->request->integer('council')) {
            $query->where('council_id', $councilId);
        }
    }

    /**
     * {@inheritDoc}
     *
     * @param  Contact  $model
     */
    protected function row(Model $model): array
    {
        return [...$model->summary(true), 'loans_count' => $model->loans_count];
    }

    /**
     * {@inheritDoc}
     */
    protected function searchColumns(): array
    {
        return ['name', 'email', 'phone', 'city', 'council.name'];
    }

    /**
     * {@inheritDoc}
     */
    protected function sorts(): array
    {
        return ['name' => 'contacts.name'];
    }

    /**
     * {@inheritDoc}
     */
    protected function applyDefaultSort(Builder $query): void
    {
        $query->orderBy('contacts.name');
    }
}
