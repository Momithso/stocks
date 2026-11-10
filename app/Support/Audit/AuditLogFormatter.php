<?php

namespace App\Support\Audit;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use OwenIt\Auditing\Models\Audit;

/**
 * Turns raw audits into readable entries of the audit log.
 *
 * Model types, names and field labels come from the models themselves (see AuditSubject), so
 * modules add their models without changing this class. Use one instance per request: names
 * are cached on the instance.
 */
class AuditLogFormatter
{
    /**
     * Audit events with their German label. Custom events are recorded with AuditRecorder.
     *
     * @var array<string, string>
     */
    public const ACTIONS = [
        'created' => 'Angelegt',
        'updated' => 'Geändert',
        'deleted' => 'Gelöscht',
        'restored' => 'Wiederhergestellt',
        'rolesUpdated' => 'Rollen geändert',
        'permissionsUpdated' => 'Rechte geändert',
        'pinReset' => 'PIN zurückgesetzt',
        'conditionChanged' => 'Zustand geändert',
        'historyEntryDeleted' => 'Verlaufseintrag gelöscht',
    ];

    /**
     * Fields that are never shown, not even for audits written before a field was excluded.
     *
     * @var list<string>
     */
    private const HIDDEN_FIELDS = ['id', 'remember_token', 'pin', 'password', 'token', 'token_hash'];

    /**
     * Maximum length of a shown value.
     *
     * @var int
     */
    private const MAX_VALUE_LENGTH = 200;

    /**
     * Names of the audited records by class and id.
     *
     * @var array<class-string, array<int, string>>
     */
    private array $names = [];

    public function __construct(private readonly AuditSubjects $subjects) {}

    /**
     * Format a page of audits.
     *
     * @param  Collection<int, Audit>  $audits
     * @return Collection<int, array{id: int, created_at: string|null, actor: array{id: int, name: string}|null, action: string, action_label: string, subject_type: string, subject_type_label: string, subject_id: int, subject_label: string, changes: list<array{field: string, label: string, old: string|null, new: string|null}>}>
     */
    public function format(Collection $audits): Collection
    {
        $this->loadNames($audits);

        return $audits->map(fn (Audit $audit) => $this->formatAudit($audit))->values();
    }

    /**
     * Options of the subject type filter.
     *
     * @return list<array{value: string, label: string}>
     */
    public function subjectTypeOptions(): array
    {
        return collect($this->subjects->all())
            ->map(fn (string $class, string $key) => ['value' => $key, 'label' => $class::auditTypeLabel()])
            ->sortBy('label')
            ->values()
            ->all();
    }

    /**
     * Options of the action filter.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function actionOptions(): array
    {
        return collect(self::ACTIONS)
            ->map(fn (string $label, string $key) => ['value' => $key, 'label' => $label])
            ->values()
            ->all();
    }

    /**
     * Load the names of the audited records with one query per class. Deleted records keep the
     * name from the values of their audits.
     *
     * @param  Collection<int, Audit>  $audits
     */
    private function loadNames(Collection $audits): void
    {
        foreach ($audits->groupBy('auditable_type') as $class => $group) {
            if (! is_a($class, AuditSubject::class, true)) {
                continue;
            }

            foreach ($class::whereIn('id', $group->pluck('auditable_id')->unique())->get() as $model) {
                $this->names[$class][$model->getKey()] = $class::auditName($model->getAttributes());
            }
        }
    }

    /**
     * Format a single audit.
     *
     * @return array<string, mixed>
     */
    private function formatAudit(Audit $audit): array
    {
        $class = $audit->auditable_type;
        $id = (int) $audit->auditable_id;
        $isSubject = is_a($class, AuditSubject::class, true);

        $name = $this->names[$class][$id] ?? null;
        if ($name === null) {
            // the record no longer exists, its last values are part of the audit
            $name = $isSubject ? $class::auditName([...$audit->old_values ?? [], ...$audit->new_values ?? []]) : '';
            $name = ($name !== '' ? $name : '#'.$id).' (gelöscht)';
        }

        return [
            'id' => $audit->id,
            'created_at' => $audit->created_at?->toIso8601String(),
            'actor' => $audit->user instanceof User ? ['id' => $audit->user->id, 'name' => $audit->user->name] : null,
            'action' => $audit->event,
            'action_label' => self::ACTIONS[$audit->event] ?? $audit->event,
            'subject_type' => $this->subjects->keyOf($class),
            'subject_type_label' => $isSubject ? $class::auditTypeLabel() : class_basename($class),
            'subject_id' => $id,
            'subject_label' => $name,
            'changes' => $this->formatChanges($isSubject ? $class::auditFieldLabels() : [], $audit->old_values ?? [], $audit->new_values ?? []),
        ];
    }

    /**
     * Format the changed fields of an audit.
     *
     * @param  array<string, string>  $labels
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     * @return list<array{field: string, label: string, old: string|null, new: string|null}>
     */
    private function formatChanges(array $labels, array $oldValues, array $newValues): array
    {
        $changes = [];
        foreach (array_unique([...array_keys($newValues), ...array_keys($oldValues)]) as $field) {
            if (in_array($field, self::HIDDEN_FIELDS, true)) {
                continue;
            }

            $changes[] = [
                'field' => $field,
                'label' => $labels[$field] ?? $field,
                'old' => $this->formatValue($oldValues[$field] ?? null),
                'new' => $this->formatValue($newValues[$field] ?? null),
            ];
        }

        return $changes;
    }

    /**
     * Format a value for display.
     */
    private function formatValue(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'Ja' : 'Nein',
            is_array($value) => $value ? implode(', ', array_map(fn ($item) => is_scalar($item) ? (string) $item : json_encode($item), $value)) : 'keine',
            default => Str::limit((string) $value, self::MAX_VALUE_LENGTH),
        };
    }
}
