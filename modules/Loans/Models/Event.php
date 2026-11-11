<?php

namespace Modules\Loans\Models;

use App\Support\Audit\AuditSubject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Event ("Veranstaltung"): only a bracket around several loans with a common material list. A new
 * loan of the event takes its times and responsible person as suggestion.
 *
 * @property int $id
 * @property string $name
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string|null $place
 * @property int|null $responsible_contact_id
 * @property string|null $notes
 */
class Event extends Model implements Auditable, AuditSubject
{
    use \OwenIt\Auditing\Auditable;

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'starts_at', 'ends_at', 'place', 'responsible_contact_id', 'notes'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'responsible_contact_id');
    }

    /**
     * @return HasMany<Loan, $this>
     */
    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class)->orderBy('pickup_at');
    }

    /**
     * Events for a select field, newest first.
     *
     * @return list<array{value: int, label: string}>
     */
    public static function options(): array
    {
        return self::where('ends_at', '>=', now()->subMonths(3))->orderByDesc('starts_at')->limit(100)->get()
            ->map(fn (Event $event) => ['value' => $event->id, 'label' => $event->name.' ('.$event->starts_at->format('d.m.Y').')'])->all();
    }

    public static function auditTypeLabel(): string
    {
        return 'Veranstaltung';
    }

    public static function auditName(array $attributes): string
    {
        return (string) ($attributes['name'] ?? '');
    }

    public static function auditFieldLabels(): array
    {
        return ['name' => 'Name', 'starts_at' => 'Beginn', 'ends_at' => 'Ende', 'place' => 'Ort', 'responsible_contact_id' => 'Verantwortlich', 'notes' => 'Notiz'];
    }
}
