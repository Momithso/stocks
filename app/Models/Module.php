<?php

namespace App\Models;

use App\Support\Audit\AuditSubject;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * The on/off switch of a module. The module itself is described by its module.json,
 * see App\Support\Modules\ModuleRegistry.
 *
 * @property int $id
 * @property string $key
 * @property bool $active
 */
class Module extends Model implements Auditable, AuditSubject
{
    use \OwenIt\Auditing\Auditable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /**
     * {@inheritDoc}
     */
    public static function auditTypeLabel(): string
    {
        return 'Modul';
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
            'active' => 'Aktiv',
        ];
    }
}
