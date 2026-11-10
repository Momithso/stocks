<?php

namespace App\Models;

use App\Enums\LabelTarget;
use App\Support\Audit\AuditSubject;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A label template: size in mm and freely placed elements. The data comes from the item or location
 * when printing (App\Support\Labels\LabelData); the elements are described in
 * App\Support\Labels\LabelElements and drawn by resources/js/labels/render.ts.
 *
 * @property int $id
 * @property string $name
 * @property LabelTarget $target
 * @property float $width_mm
 * @property float $height_mm
 * @property list<array<string, mixed>> $elements
 * @property bool $is_default preselected when printing for its target
 */
class LabelTemplate extends Model implements Auditable, AuditSubject
{
    use \OwenIt\Auditing\Auditable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'target',
        'width_mm',
        'height_mm',
        'elements',
        'is_default',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target' => LabelTarget::class,
            'width_mm' => 'float',
            'height_mm' => 'float',
            'elements' => 'array',
            'is_default' => 'boolean',
        ];
    }

    /**
     * Make this template the default of its target, and no other one.
     */
    public function makeDefault(): void
    {
        self::where('target', $this->target)->whereKeyNot($this->id)->where('is_default', true)
            ->get()->each(fn (self $other) => $other->update(['is_default' => false]));
        $this->update(['is_default' => true]);
    }

    /**
     * Data for the frontend, with urls of the uploaded images.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'target' => $this->target->value,
            'target_label' => $this->target->label(),
            'width_mm' => $this->width_mm,
            'height_mm' => $this->height_mm,
            'elements' => $this->elements,
            'is_default' => $this->is_default,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public static function auditTypeLabel(): string
    {
        return 'Etikettenvorlage';
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
            'target' => 'Art',
            'width_mm' => 'Breite',
            'height_mm' => 'Höhe',
            'elements' => 'Elemente',
            'is_default' => 'Standard',
        ];
    }
}
