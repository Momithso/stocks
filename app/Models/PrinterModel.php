<?php

namespace App\Models;

use App\Support\Audit\AuditSubject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A model of niimbot label printers, e.g. the B1. Admins add new models in the settings without
 * programming (permission labels.manage).
 *
 * @property int $id
 * @property string $name
 * @property int $dpi dots per inch, 203 means 8 dots per mm
 * @property float $max_width_mm width of the print head; wider labels are printed in the middle
 * @property string $print_task print task of NiimBlueLib, e.g. "B1"
 * @property list<array{width: float, height: float}> $label_sizes label sizes in mm
 */
class PrinterModel extends Model implements Auditable, AuditSubject
{
    use \OwenIt\Auditing\Auditable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'dpi',
        'max_width_mm',
        'print_task',
        'label_sizes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dpi' => 'integer',
            'max_width_mm' => 'float',
            'label_sizes' => 'array',
        ];
    }

    /**
     * @return HasMany<Printer, $this>
     */
    public function printers(): HasMany
    {
        return $this->hasMany(Printer::class);
    }

    /**
     * Data for the frontend.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return $this->only(['id', 'name', 'dpi', 'max_width_mm', 'print_task', 'label_sizes']);
    }

    /**
     * {@inheritDoc}
     */
    public static function auditTypeLabel(): string
    {
        return 'Druckermodell';
    }

    /**
     * {@inheritDoc}
     */
    public static function auditName(array $attributes): string
    {
        return (string) ($attributes['name'] ?? '');
    }

    /**
     * {@inheritDoc}
     */
    public static function auditFieldLabels(): array
    {
        return [
            'name' => 'Name',
            'dpi' => 'Auflösung',
            'max_width_mm' => 'Druckbreite',
            'print_task' => 'Drucktyp',
            'label_sizes' => 'Etikettengrößen',
        ];
    }
}
