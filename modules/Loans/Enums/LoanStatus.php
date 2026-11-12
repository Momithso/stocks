<?php

namespace Modules\Loans\Enums;

use App\Enums\HasOptions;

/**
 * Status of a loan. A loan runs from left to right; only "confirmed" and "issued" reserve its
 * items. "Overdue" is no status: an issued loan is overdue once its return time has passed.
 */
enum LoanStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Issued = 'issued';
    case ReturnedUnchecked = 'unchecked';
    case Closed = 'closed';
    case ClosedWithDefects = 'defects';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Entwurf',
            self::Confirmed => 'Bestätigt',
            self::Issued => 'Ausgegeben',
            self::ReturnedUnchecked => 'Angenommen, ungeprüft',
            self::Closed => 'Abgeschlossen',
            self::ClosedWithDefects => 'Abgeschlossen mit Mängeln',
            self::Cancelled => 'Storniert',
        };
    }

    /**
     * Colour of the badge (UiBadge variant).
     */
    public function variant(): string
    {
        return match ($this) {
            self::Draft => 'neutral',
            self::Confirmed => 'accent',
            self::Issued => 'warning',
            self::ReturnedUnchecked => 'warning',
            self::Closed => 'success',
            self::ClosedWithDefects => 'danger',
            self::Cancelled => 'neutral',
        };
    }

    /**
     * Statuses whose loans hold their items, so no other loan can have them at the same time.
     *
     * @return list<self>
     */
    public static function reserving(): array
    {
        return [self::Confirmed, self::Issued];
    }

    /**
     * Check if positions and times can still be changed (nothing was issued yet).
     */
    public function isOpen(): bool
    {
        return $this === self::Draft || $this === self::Confirmed;
    }
}
