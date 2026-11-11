<?php

namespace Modules\Loans\Enums;

/**
 * Documents of a loan. Contract and material list are frozen at the issue, a return receipt at
 * every return. The confirmation is made on request from the current data.
 */
enum DocumentType: string
{
    case Confirmation = 'confirmation';
    case Contract = 'contract';
    case MaterialList = 'list';
    case ReturnReceipt = 'return_receipt';
    case PaperScan = 'paper_scan';

    public function label(): string
    {
        return match ($this) {
            self::Confirmation => 'Buchungsbestätigung',
            self::Contract => 'Leihvertrag',
            self::MaterialList => 'Materialliste',
            self::ReturnReceipt => 'Rückgabebeleg',
            self::PaperScan => 'Unterschriebenes Blatt',
        };
    }
}
