<?php

namespace Modules\Loans\Support;

use App\Support\Table\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Models\Loan;

/**
 * List of loans. Filters: status ("overdue" for issued loans past their return), time (overlapping
 * from/to), contact, event.
 */
class LoansTable extends Table
{
    /**
     * {@inheritDoc}
     */
    protected function query(): Builder
    {
        return Loan::query()->with(['responsible', 'responsibleUser', 'borrower', 'event']);
    }

    /**
     * {@inheritDoc}
     */
    protected function applyFilters(Builder $query): void
    {
        $status = (string) $this->request->query('status');
        if ($status === 'overdue') {
            $query->overdue();
        } elseif ($status === 'open') {
            $query->whereNotIn('status', [LoanStatus::Closed, LoanStatus::Cancelled]);
        } elseif ($status !== '' && LoanStatus::tryFrom($status)) {
            $query->where('status', $status);
        }

        if ($from = $this->request->query('from')) {
            $query->where('return_at', '>=', Carbon::parse($from)->startOfDay());
        }
        if ($to = $this->request->query('to')) {
            $query->where('pickup_at', '<=', Carbon::parse($to)->endOfDay());
        }
        if ($contactId = $this->request->integer('contact')) {
            $query->where(fn (Builder $query) => $query->where('responsible_contact_id', $contactId)->orWhere('borrower_contact_id', $contactId));
        }
        if ($eventId = $this->request->integer('event')) {
            $query->where('event_id', $eventId);
        }
    }

    /**
     * {@inheritDoc}
     *
     * @param  Loan  $model
     */
    protected function row(Model $model): array
    {
        return [
            ...$model->summary(),
            'event' => $model->event?->name,
            'purpose' => $model->purpose,
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function searchColumns(): array
    {
        return ['number', 'purpose', 'responsible.name', 'borrower.name', 'event.name'];
    }

    /**
     * {@inheritDoc}
     */
    protected function sorts(): array
    {
        return ['number' => 'loans.number', 'pickup' => 'loans.pickup_at', 'return' => 'loans.return_at', 'status' => 'loans.status'];
    }

    /**
     * {@inheritDoc}
     */
    protected function applyDefaultSort(Builder $query): void
    {
        $query->orderByDesc('loans.pickup_at');
    }
}
