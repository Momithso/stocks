<?php

namespace App\Enums;

/**
 * Level of a location in the tree. Every level lies in the one before it.
 */
enum LocationType: string
{
    use HasOptions;

    case Address = 'address';
    case Room = 'room';
    case Shelf = 'shelf';
    case Compartment = 'compartment';

    public function label(): string
    {
        return match ($this) {
            self::Address => 'Adresse',
            self::Room => 'Raum',
            self::Shelf => 'Lagerplatz',
            self::Compartment => 'Fach',
        };
    }

    /**
     * Level a child of this level has, null for the last level.
     */
    public function childType(): ?self
    {
        return match ($this) {
            self::Address => self::Room,
            self::Room => self::Shelf,
            self::Shelf => self::Compartment,
            self::Compartment => null,
        };
    }

    /**
     * Character that joins the code to the code of the parent: "EU-014-R03.4".
     */
    public function separator(): string
    {
        return $this === self::Compartment ? '.' : '-';
    }
}
