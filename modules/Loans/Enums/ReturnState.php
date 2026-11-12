<?php

namespace Modules\Loans\Enums;

use App\Enums\HasOptions;

/**
 * How a position came back. Everything but "ok" is a defect and closes the loan "with defects".
 * Damaged, dirty and incomplete also describe a defect found at the issue (issue_state).
 */
enum ReturnState: string
{
    use HasOptions;

    case Ok = 'ok';
    case Missing = 'missing';
    case Damaged = 'damaged';
    case Dirty = 'dirty';
    case Incomplete = 'incomplete';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'zurück',
            self::Missing => 'fehlt',
            self::Damaged => 'beschädigt',
            self::Dirty => 'verschmutzt',
            self::Incomplete => 'unvollständig',
        };
    }
}
