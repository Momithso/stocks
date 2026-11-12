<?php

namespace Modules\Loans\Enums;

/**
 * Who signed: at the issue the responsible person and the issuer of the organization, at the
 * return the person giving back and the member receiving.
 */
enum SignatureRole: string
{
    case Responsible = 'responsible';
    case Issuer = 'issuer';
    case Returner = 'returner';
    case Receiver = 'receiver';

    public function label(): string
    {
        return match ($this) {
            self::Responsible => 'Verantwortliche Person',
            self::Issuer => 'Für den Verleiher',
            self::Returner => 'Rückgabe durch',
            self::Receiver => 'Angenommen von',
        };
    }
}
