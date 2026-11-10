<?php

namespace App\Enums;

/**
 * Options of an enum with a German label for select fields in the frontend.
 */
trait HasOptions
{
    /**
     * German label of the case.
     */
    abstract public function label(): string;

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
