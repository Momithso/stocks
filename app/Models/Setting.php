<?php

namespace App\Models;

use App\Support\Audit\AuditSubject;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A stored setting of the instance. Read and write settings with App\Support\Settings, which knows
 * the defaults and caches the values.
 *
 * @property string $key
 * @property mixed $value
 */
class Setting extends Model implements Auditable, AuditSubject
{
    use \OwenIt\Auditing\Auditable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    /**
     * {@inheritDoc}
     */
    public static function auditTypeLabel(): string
    {
        return 'Einstellung';
    }

    /**
     * {@inheritDoc}
     */
    public static function auditName(array $attributes): string
    {
        return (string) ($attributes['key'] ?? '');
    }

    /**
     * {@inheritDoc}
     */
    public static function auditFieldLabels(): array
    {
        return [
            'key' => 'Schlüssel',
            'value' => 'Wert',
        ];
    }
}
