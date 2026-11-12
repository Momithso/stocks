<?php

namespace Modules\Loans\Models;

use App\Support\Audit\AuditSubject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Borrower or responsible person of a loan. Contacts have no account. Their data are only shown to
 * members with "loans.manage"; everybody else sees the name.
 *
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $street
 * @property string|null $zip
 * @property string|null $city
 * @property int|null $council_id empty: private person
 * @property string|null $notes
 */
class Contact extends Model implements Auditable, AuditSubject
{
    use \OwenIt\Auditing\Auditable;

    /**
     * Fields a contact needs to be responsible for a confirmed loan.
     */
    public const REQUIRED = ['name', 'email', 'phone', 'street', 'zip', 'city'];

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'email', 'phone', 'street', 'zip', 'city', 'council_id', 'notes'];

    /**
     * @return BelongsTo<Council, $this>
     */
    public function council(): BelongsTo
    {
        return $this->belongsTo(Council::class);
    }

    /**
     * Loans the contact borrowed or is responsible for.
     *
     * @return HasMany<Loan, $this>
     */
    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class, 'responsible_contact_id');
    }

    /**
     * Loans where the contact is borrower or responsible person, newest first.
     */
    public function allLoans()
    {
        return Loan::where('responsible_contact_id', $this->id)->orWhere('borrower_contact_id', $this->id)->latest('pickup_at');
    }

    /**
     * Check if name, e-mail, phone and address are set.
     */
    public function isComplete(): bool
    {
        return $this->missingFields() === [];
    }

    /**
     * German names of the fields that are still empty.
     *
     * @return list<string>
     */
    public function missingFields(): array
    {
        $labels = self::auditFieldLabels();

        return array_values(array_map(fn (string $field) => $labels[$field], array_filter(self::REQUIRED, fn (string $field) => trim((string) $this->{$field}) === '')));
    }

    /**
     * Address in one line, e.g. "Eupener Str. 70, 52066 Aachen".
     */
    public function address(): string
    {
        return trim(implode(', ', array_filter([$this->street, trim("{$this->zip} {$this->city}")])), ', ');
    }

    /**
     * Council name or "privat".
     */
    public function affiliation(): string
    {
        return $this->council?->name ?? 'privat';
    }

    /**
     * Data for the frontend. Without "loans.manage" only id and name.
     *
     * @return array<string, mixed>
     */
    public function summary(bool $withDetails): array
    {
        $summary = ['id' => $this->id, 'name' => $this->name];

        return $withDetails ? [
            ...$summary,
            ...$this->only(['email', 'phone', 'street', 'zip', 'city', 'council_id', 'notes']),
            'address' => $this->address(),
            'affiliation' => $this->affiliation(),
            'missing' => $this->missingFields(),
        ] : $summary;
    }

    public static function auditTypeLabel(): string
    {
        return 'Kontakt';
    }

    public static function auditName(array $attributes): string
    {
        return (string) ($attributes['name'] ?? '');
    }

    public static function auditFieldLabels(): array
    {
        return ['name' => 'Name', 'email' => 'E-Mail', 'phone' => 'Telefon', 'street' => 'Straße', 'zip' => 'PLZ', 'city' => 'Ort', 'council_id' => 'Fachschaft', 'notes' => 'Notiz'];
    }
}
