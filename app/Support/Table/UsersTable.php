<?php

namespace App\Support\Table;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Accounts of the account management.
 */
class UsersTable extends Table
{
    /**
     * {@inheritDoc}
     */
    protected function query(): Builder
    {
        return User::query()->with('roles:id,name');
    }

    /**
     * {@inheritDoc}
     *
     * @param  User  $model
     */
    protected function row(Model $model): array
    {
        return [
            'id' => $model->id,
            'firstname' => $model->firstname,
            'lastname' => $model->lastname,
            'email' => $model->email,
            'roles' => $model->roles->map(fn ($role) => $role->only(['id', 'name']))->values()->all(),
            'is_disabled' => $model->is_disabled,
            'has_pin' => $model->hasPin(),
            'last_login_at' => $model->last_login_at?->toIso8601String(),
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function searchColumns(): array
    {
        return ['firstname', 'lastname', 'email'];
    }

    /**
     * {@inheritDoc}
     */
    protected function sorts(): array
    {
        return [
            'firstname' => 'users.firstname',
            'lastname' => 'users.lastname',
            'email' => 'users.email',
            'last_login_at' => 'users.last_login_at',
            'roles' => fn (Builder $query, string $direction) => $this->orderByConcatenatedValues(
                $query,
                DB::table('roles')
                    ->join('model_has_roles', 'model_has_roles.role_id', '=', 'roles.id')
                    ->whereColumn('model_has_roles.model_id', 'users.id')
                    ->where('model_has_roles.model_type', User::class),
                'roles.name',
                $direction,
            ),
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function applyDefaultSort(Builder $query): void
    {
        $query->orderBy('users.lastname')->orderBy('users.firstname');
    }
}
