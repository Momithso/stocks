<?php

namespace Modules\Loans\Models;

use App\Models\User;
use App\Support\Audit\AuditSubject;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\RoutesNotifications;
use Illuminate\Support\Carbon;
use Modules\Loans\Enums\DocumentType;
use Modules\Loans\Enums\LoanKind;
use Modules\Loans\Enums\LoanStatus;
use NotificationChannels\WebPush\HasPushSubscriptions;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A loan ("Buchung"): who borrows what from when to when. Three persons: the borrower, the
 * responsible person (liable, contact person; often the same contact) and the issuer of the
 * organization, who is set at the issue. Internal loans (own use) have a responsible member instead
 * of contacts and no contract.
 *
 * The status only changes through the actions in Modules\Loans\Actions.
 *
 * The loan stands for its borrower side in notifications: mails go to the responsible person (and
 * the borrower, if that is another contact), push to the devices that switched it on on the
 * borrower page (Modules\Loans\Support\LoanPage).
 *
 * @property int $id
 * @property string $number
 * @property LoanStatus $status
 * @property LoanKind $kind
 * @property int|null $borrower_contact_id
 * @property int|null $responsible_contact_id
 * @property int|null $responsible_user_id
 * @property int|null $issuer_id
 * @property int|null $event_id
 * @property Carbon $pickup_at
 * @property Carbon $return_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $issued_at
 * @property Carbon|null $returned_at
 * @property Carbon|null $inspected_at
 * @property Carbon|null $defects_closed_at
 * @property string|null $defects_note
 * @property string|null $purpose
 * @property string|null $notes
 * @property string|null $pack_pin
 * @property int $pin_attempts
 * @property string|null $contract_text
 * @property Carbon|null $cancelled_at
 * @property string|null $cancelled_reason
 * @property int|null $created_by
 * @property int $access_version version of the key of the borrower page
 * @property Carbon|null $access_blocked_at
 * @property array<string, array<string, bool>>|null $notification_preferences settings of the borrower page
 */
class Loan extends Model implements Auditable, AuditSubject
{
    use HasPushSubscriptions;
    use \OwenIt\Auditing\Auditable;
    use RoutesNotifications;

    /**
     * Cache of isOverdue(): something of the loan is out.
     */
    private ?bool $isOut = null;

    /**
     * Fields set by the form. Status, number and the times of the steps are set by the actions.
     *
     * @var list<string>
     */
    protected $fillable = ['kind', 'borrower_contact_id', 'responsible_contact_id', 'responsible_user_id', 'event_id', 'pickup_at', 'return_at', 'purpose', 'notes'];

    /**
     * The pin is not shown in the audit; the contract text is long and frozen anyway.
     *
     * @var list<string>
     */
    protected $auditExclude = ['pack_pin', 'pin_attempts', 'contract_text', 'notification_preferences'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LoanStatus::class,
            'kind' => LoanKind::class,
            'pickup_at' => 'datetime',
            'return_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'issued_at' => 'datetime',
            'returned_at' => 'datetime',
            'inspected_at' => 'datetime',
            'defects_closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'pack_pin' => 'encrypted',
            'pin_attempts' => 'integer',
            'access_version' => 'integer',
            'access_blocked_at' => 'datetime',
            'notification_preferences' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'borrower_contact_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'responsible_contact_id');
    }

    /**
     * Responsible member of an internal loan.
     *
     * @return BelongsTo<User, $this>
     */
    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issuer_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Positions of the first level (items and boxes, not the contents of boxes).
     *
     * @return HasMany<LoanItem, $this>
     */
    public function positions(): HasMany
    {
        return $this->hasMany(LoanItem::class)->whereNull('parent_id')->orderBy('id');
    }

    /**
     * All rows, contents of boxes included.
     *
     * @return HasMany<LoanItem, $this>
     */
    public function loanItems(): HasMany
    {
        return $this->hasMany(LoanItem::class)->orderBy('id');
    }

    /**
     * @return HasMany<LoanSignature, $this>
     */
    public function signatures(): HasMany
    {
        return $this->hasMany(LoanSignature::class)->orderBy('id');
    }

    /**
     * @return HasMany<LoanDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(LoanDocument::class)->orderBy('id');
    }

    /**
     * Chat of the borrower page.
     *
     * @return HasMany<LoanMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(LoanMessage::class)->orderBy('id');
    }

    /**
     * Requests of the borrower page for a later return time, newest first.
     *
     * @return HasMany<LoanExtensionRequest, $this>
     */
    public function extensionRequests(): HasMany
    {
        return $this->hasMany(LoanExtensionRequest::class)->latest('id');
    }

    /**
     * Log of the notifications, newest first.
     *
     * @return HasMany<LoanNotification, $this>
     */
    public function notificationLog(): HasMany
    {
        return $this->hasMany(LoanNotification::class)->latest('sent_at')->latest('id');
    }

    /**
     * Mail addresses of the borrower side: the responsible person, and the borrower if that is
     * another contact with an address.
     *
     * @return array<string, string>
     */
    public function routeNotificationForMail(): array
    {
        if ($this->isInternal()) {
            return [];
        }

        return collect([$this->responsible, $this->borrower])->filter(fn (?Contact $contact) => filled($contact?->email))
            ->mapWithKeys(fn (Contact $contact) => [mb_strtolower($contact->email) => $contact->name])->all();
    }

    /**
     * Settings of the borrower page: deviations from the defaults.
     *
     * @return array<string, array<string, bool>>|null
     */
    public function notificationPreferences(): ?array
    {
        return $this->notification_preferences;
    }

    /**
     * Document of the issue of a type (contract or material list).
     */
    public function issueDocument(DocumentType $type): ?LoanDocument
    {
        return $this->documents->firstWhere('type', $type);
    }

    /**
     * Loans whose items are held at the moment or in the future: confirmed and issued ones.
     */
    #[Scope]
    protected function reserving(Builder $query): void
    {
        $query->whereIn('status', LoanStatus::reserving());
    }

    /**
     * Issued loans whose return time has passed and that still have something out. The rest of a
     * partial issue that never left does not make a loan overdue.
     */
    #[Scope]
    protected function overdue(Builder $query): void
    {
        $query->where('status', LoanStatus::Issued)->where('return_at', '<', now())
            ->whereHas('loanItems', fn (Builder $query) => $query->whereNotNull('issued_at')->whereNull('returned_at'));
    }

    /**
     * Confirmed loans whose return time has passed without a pickup ("nicht abgeholt").
     */
    #[Scope]
    protected function notPickedUp(Builder $query): void
    {
        $query->where('status', LoanStatus::Confirmed)->where('return_at', '<', now());
    }

    /**
     * Check if the loan is issued, its return time has passed and something is still out.
     */
    public function isOverdue(): bool
    {
        return $this->status === LoanStatus::Issued && $this->return_at->isPast()
            && ($this->isOut ??= $this->loanItems()->whereNotNull('issued_at')->whereNull('returned_at')->exists());
    }

    /**
     * Check if the loan is confirmed and its return time has passed without a pickup. It holds
     * nothing any more and waits to be cancelled (or to get a new time).
     */
    public function isNotPickedUp(): bool
    {
        return $this->status === LoanStatus::Confirmed && $this->return_at->isPast();
    }

    /**
     * Lock the row of the loan until the end of the transaction and load its current state, so a
     * task sent twice (double tap, repeated request) runs only once. Call it inside DB::transaction().
     */
    public function lockForTask(): void
    {
        $fresh = self::whereKey($this->id)->lockForUpdate()->firstOrFail();
        $this->setRawAttributes($fresh->getAttributes(), true);
        $this->isOut = null;
    }

    /**
     * Days the loan is overdue, 0 if it is not.
     */
    public function overdueDays(): int
    {
        return $this->isOverdue() ? max(1, (int) floor($this->return_at->diffInHours(now()) / 24)) : 0;
    }

    /**
     * End of the time the loan holds its items: an overdue loan holds them until they are back.
     */
    public function holdsUntil(): Carbon
    {
        return $this->isOverdue() ? now()->max($this->return_at) : $this->return_at;
    }

    /**
     * Check if the loan is internal (own use, no contract and no signatures).
     */
    public function isInternal(): bool
    {
        return $this->kind === LoanKind::Internal;
    }

    /**
     * Name of the responsible person: the contact, or the member of an internal loan.
     */
    public function responsibleName(): ?string
    {
        return $this->isInternal() ? $this->responsibleUser?->name : $this->responsible?->name;
    }

    /**
     * Short description for lists and notices: "B-2026-0042 · Erstiwoche".
     */
    public function title(): string
    {
        return trim($this->number.' · '.($this->purpose ?: $this->borrower?->name ?: $this->responsibleName()), ' ·');
    }

    /**
     * Data for lists, calendars and notices.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'title' => $this->title(),
            'status' => $this->status->value,
            'status_label' => $this->isNotPickedUp() ? 'Nicht abgeholt' : $this->status->label(),
            'status_variant' => $this->isNotPickedUp() ? 'danger' : $this->status->variant(),
            'not_picked_up' => $this->isNotPickedUp(),
            'kind' => $this->kind->value,
            'pickup_at' => $this->pickup_at->toIso8601String(),
            'return_at' => $this->return_at->toIso8601String(),
            'overdue_days' => $this->overdueDays(),
            'responsible' => $this->responsibleName(),
        ];
    }

    public static function auditTypeLabel(): string
    {
        return 'Buchung';
    }

    public static function auditName(array $attributes): string
    {
        return (string) ($attributes['number'] ?? '');
    }

    public static function auditFieldLabels(): array
    {
        return [
            'number' => 'Nummer', 'status' => 'Status', 'kind' => 'Art', 'borrower_contact_id' => 'Entleiher', 'responsible_contact_id' => 'Verantwortlich',
            'responsible_user_id' => 'Verantwortliches Mitglied', 'issuer_id' => 'Herausgeber', 'event_id' => 'Veranstaltung', 'pickup_at' => 'Abholung',
            'return_at' => 'Rückgabe', 'confirmed_at' => 'Bestätigt am', 'issued_at' => 'Ausgegeben am', 'returned_at' => 'Zurück am', 'inspected_at' => 'Geprüft am',
            'defects_closed_at' => 'Mängel abgeschlossen am', 'defects_note' => 'Notiz zu den Mängeln', 'purpose' => 'Anlass', 'notes' => 'Notiz',
            'cancelled_at' => 'Storniert am', 'cancelled_reason' => 'Grund der Stornierung',
        ];
    }
}
