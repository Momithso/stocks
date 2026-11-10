<?php

namespace App\Models;

use App\Enums\PrinterDriver;
use App\Support\Audit\AuditSubject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A printer for labels. The driver decides how the frontend prints (resources/js/labels/drivers).
 *
 * Settings of the driver "paper":
 * - page: "single" (one label per page, for label printers at a pc) or "sheet" (A4 sheet)
 * - columns, rows, margin_top, margin_left, gap_x, gap_y: layout of the sheet in mm
 *
 * Settings of the driver "niimbot":
 * - density: 1 to 5
 * - offset_x, offset_y: shift of the print in mm
 * - rotation: 0, 90, 180 or 270 degrees
 *
 * @property int $id
 * @property string $name
 * @property PrinterDriver $driver
 * @property int|null $printer_model_id only for niimbot
 * @property array<string, mixed> $settings
 */
class Printer extends Model implements Auditable, AuditSubject
{
    use \OwenIt\Auditing\Auditable;

    /**
     * Default settings per driver; stored settings are merged over them.
     *
     * @var array<string, array<string, mixed>>
     */
    public const DEFAULT_SETTINGS = [
        'paper' => ['page' => 'single', 'columns' => 2, 'rows' => 8, 'margin_top' => 10, 'margin_left' => 10, 'gap_x' => 5, 'gap_y' => 5],
        'niimbot' => ['density' => 3, 'offset_x' => 0, 'offset_y' => 0, 'rotation' => 0],
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'driver',
        'printer_model_id',
        'settings',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'driver' => PrinterDriver::class,
            'settings' => 'array',
        ];
    }

    /**
     * @return BelongsTo<PrinterModel, $this>
     */
    public function printerModel(): BelongsTo
    {
        return $this->belongsTo(PrinterModel::class);
    }

    /**
     * Data for the frontend, with the model of niimbot printers.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'driver' => $this->driver->value,
            'driver_label' => $this->driver->label(),
            'printer_model_id' => $this->printer_model_id,
            'model' => $this->printerModel?->summary(),
            'settings' => [...self::DEFAULT_SETTINGS[$this->driver->value], ...$this->settings],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public static function auditTypeLabel(): string
    {
        return 'Drucker';
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
            'driver' => 'Treiber',
            'printer_model_id' => 'Modell',
            'settings' => 'Einstellungen',
        ];
    }
}
