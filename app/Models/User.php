<?php

namespace App\Models;

use App\Support\Audit\AuditSubject;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\Permission\Traits\HasRoles;

/**
 * An account of a member. Accounts are only created by invitation (see App\Actions\Users\InviteUser).
 *
 * @property int $id
 * @property string $firstname
 * @property string $lastname
 * @property string $email
 * @property string|null $pin hash of the kiosk pin
 * @property bool $is_disabled
 */
class User extends Authenticatable implements Auditable, AuditSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;
    use \OwenIt\Auditing\Auditable;

    /**
     * The attributes that are mass assignable. Password and pin are set explicitly.
     *
     * @var list<string>
     */
    protected $fillable = [
        'firstname',
        'lastname',
        'email',
        'is_disabled',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'pin',
        'remember_token',
    ];

    /**
     * Attributes to exclude from the audit.
     *
     * @var list<string>
     */
    protected $auditExclude = [
        'password',
        'pin',
        'remember_token',
        'last_login_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'pin' => 'hashed',
            'is_disabled' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * E-mail addresses are stored in lower case, so the login is case insensitive.
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => mb_strtolower(trim($value)));
    }

    /**
     * Full name of the user.
     */
    protected function name(): Attribute
    {
        return Attribute::make(get: fn () => trim($this->firstname.' '.$this->lastname));
    }

    /**
     * Check if the user has set a kiosk pin.
     */
    public function hasPin(): bool
    {
        return $this->pin !== null;
    }

    /**
     * Check the given kiosk pin.
     */
    public function isPinValid(string $pin): bool
    {
        return $this->hasPin() && Hash::check($pin, $this->pin);
    }

    /**
     * Check if the user is the last active account with the admin role.
     */
    public function isLastAdmin(): bool
    {
        if (! $this->hasRole(Role::admin())) {
            return false;
        }

        return ! self::role(Role::admin())->where('is_disabled', false)->whereKeyNot($this->id)->exists();
    }

    /**
     * {@inheritDoc}
     */
    public static function auditTypeLabel(): string
    {
        return 'Konto';
    }

    /**
     * {@inheritDoc}
     */
    public static function auditName(array $attributes): string
    {
        return trim(($attributes['firstname'] ?? '').' '.($attributes['lastname'] ?? ''));
    }

    /**
     * {@inheritDoc}
     */
    public static function auditFieldLabels(): array
    {
        return [
            'firstname' => 'Vorname',
            'lastname' => 'Nachname',
            'email' => 'E-Mail',
            'is_disabled' => 'Deaktiviert',
            'roles' => 'Rollen',
        ];
    }
}
