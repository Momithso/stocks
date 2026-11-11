<?php

namespace App\Enums;

/**
 * Kind of a row in the movement log of an item.
 */
enum MovementType: string
{
    use HasOptions;

    case Create = 'create';
    case Move = 'move';
    case Split = 'split';
    case Count = 'count';
    case Retire = 'retire';
    case Consume = 'consume';
    case Merge = 'merge';
    // written by modules: an item leaves with a loan and comes back (note names the loan)
    case Issue = 'issue';
    case Return = 'return';

    public function label(): string
    {
        return match ($this) {
            self::Create => 'angelegt',
            self::Move => 'umgelagert',
            self::Split => 'geteilt',
            self::Count => 'gezählt',
            self::Retire => 'ausgemustert',
            self::Consume => 'verbraucht',
            self::Merge => 'zurückgebucht',
            self::Issue => 'ausgegeben',
            self::Return => 'zurückgenommen',
        };
    }
}
