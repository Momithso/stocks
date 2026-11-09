<?php

namespace App\Models;

use App\Support\Audit\AuditSubject;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * A bundle of permissions. The code only checks permissions, never role names.
 *
 * The admin role is a system role: it always has every permission and can not be changed or deleted.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property bool $is_system
 */
class Role extends SpatieRole implements Auditable, AuditSubject
{
    use \OwenIt\Auditing\Auditable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /**
     * Name of the admin role.
     */
    public static function admin(): string
    {
        return config('stocks.admin_role');
    }

    /**
     * {@inheritDoc}
     */
    public static function auditTypeLabel(): string
    {
        return 'Rolle';
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
            'description' => 'Beschreibung',
            'permissions' => 'Rechte',
        ];
    }
}
