<?php

namespace App\Models;

use App\Support\Audit\AuditSubject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * An invitation to create an account. Only the hash of the token is stored, the token itself is
 * only part of the link in the invitation mail.
 *
 * @property int $id
 * @property string $email
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 */
class Invitation extends Model implements Auditable, AuditSubject
{
    use HasFactory;
    use \OwenIt\Auditing\Auditable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'token_hash',
        'invited_by_id',
        'expires_at',
        'accepted_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'token_hash',
    ];

    /**
     * Attributes to exclude from the audit.
     *
     * @var list<string>
     */
    protected $auditExclude = [
        'token_hash',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * Find the open invitation of a token from an invitation link.
     */
    public static function findOpenByToken(string $token): ?self
    {
        return self::open()->where('token_hash', hash('sha256', $token))->first();
    }

    /**
     * Invitations that are neither accepted nor expired.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_id');
    }

    /**
     * {@inheritDoc}
     */
    public static function auditTypeLabel(): string
    {
        return 'Einladung';
    }

    /**
     * {@inheritDoc}
     */
    public static function auditName(array $attributes): string
    {
        return (string) ($attributes['email'] ?? '');
    }

    /**
     * {@inheritDoc}
     */
    public static function auditFieldLabels(): array
    {
        return [
            'email' => 'E-Mail',
            'invited_by_id' => 'Eingeladen von',
            'expires_at' => 'Gültig bis',
            'accepted_at' => 'Angenommen am',
        ];
    }
}
