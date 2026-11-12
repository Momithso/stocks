<?php

namespace Modules\Loans\Enums;

use App\Enums\HasOptions;

/**
 * External loans go to a contact with contract and signatures; internal loans are the own use of
 * the organization and only have a material list.
 */
enum LoanKind: string
{
    use HasOptions;

    case External = 'external';
    case Internal = 'internal';

    public function label(): string
    {
        return match ($this) {
            self::External => 'Verleih',
            self::Internal => 'Eigenbedarf',
        };
    }
}
