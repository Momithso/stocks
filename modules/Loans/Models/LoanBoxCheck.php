<?php

namespace Modules\Loans\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Check of a box with the pack pin on its public page: who checked, when, and which numbers were
 * ticked off. It shows the issuer what was checked already; it replaces neither scan nor signature.
 *
 * @property int $id
 * @property int $loan_item_id
 * @property string $name
 * @property list<string> $ticks numbers ticked off
 * @property int $total rows of the box at the check
 * @property Carbon $checked_at
 */
class LoanBoxCheck extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'ticks', 'total', 'checked_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['ticks' => 'array', 'checked_at' => 'datetime', 'total' => 'integer'];
    }
}
