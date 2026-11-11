<?php

namespace Modules\Loans\Models;

use App\Support\Audit\AuditSubject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Student council ("Fachschaft") a contact belongs to. Admins keep the list; only active
 * councils can be chosen, without any only "privat" is left.
 *
 * @property int $id
 * @property int $number
 * @property string $name
 * @property bool $is_active
 */
class Council extends Model implements Auditable, AuditSubject
{
    use \OwenIt\Auditing\Auditable;

    /**
     * @var list<string>
     */
    protected $fillable = ['number', 'name', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'number' => 'integer'];
    }

    /**
     * @return HasMany<Contact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /**
     * Active councils for a select field.
     *
     * @return list<array{value: int, label: string}>
     */
    public static function options(): array
    {
        return self::where('is_active', true)->orderBy('number')->get()
            ->map(fn (Council $council) => ['value' => $council->id, 'label' => $council->name])->all();
    }

    public static function auditTypeLabel(): string
    {
        return 'Fachschaft';
    }

    public static function auditName(array $attributes): string
    {
        return (string) ($attributes['name'] ?? '');
    }

    public static function auditFieldLabels(): array
    {
        return ['number' => 'Nummer', 'name' => 'Name', 'is_active' => 'Aktiv'];
    }
}
