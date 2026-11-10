<?php

namespace App\Enums;

/**
 * What a label template is made for. The target decides which variables a template can use.
 */
enum LabelTarget: string
{
    use HasOptions;

    case Item = 'item';
    case Box = 'box';
    case Location = 'location';
    // stock labels with a reserved number, before the item exists
    case Reserve = 'reserve';

    public function label(): string
    {
        return match ($this) {
            self::Item => 'Gegenstand',
            self::Box => 'Box',
            self::Location => 'Standort',
            self::Reserve => 'Vorrat',
        };
    }

    /**
     * Variables of the target, see App\Support\Labels\LabelData.
     *
     * @return list<string>
     */
    public function variables(): array
    {
        return match ($this) {
            self::Item => ['name', 'number', 'category', 'quantity', 'home', 'location', 'owner', 'date', 'description', 'condition', 'contact', 'url', 'hint'],
            self::Box => ['name', 'number', 'category', 'quantity', 'home', 'location', 'owner', 'date', 'description', 'condition', 'contact', 'url', 'hint', 'count'],
            self::Location => ['name', 'code', 'location', 'owner', 'date', 'description', 'contact', 'url'],
            self::Reserve => ['number', 'owner', 'date', 'contact', 'url'],
        };
    }
}
