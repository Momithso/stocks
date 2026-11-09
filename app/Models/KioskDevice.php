<?php

namespace App\Models;

use App\Support\Audit\AuditSubject;
use App\Support\QrCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A tablet that may open the kiosk.
 *
 * A tablet is paired once by opening the pairing url. The id and token are then stored in a long
 * living cookie (see App\Http\Middleware\EnsureKioskDevice). Deleting the device or creating a new
 * token locks the tablet out.
 *
 * @property int $id
 * @property string $name
 * @property string $token
 * @property Carbon|null $last_used_at
 */
class KioskDevice extends Model implements Auditable, AuditSubject
{
    use HasFactory;
    use \OwenIt\Auditing\Auditable;

    /**
     * Name of the cookie that stores the id and the token of the paired device.
     *
     * @var string
     */
    public const COOKIE_NAME = 'stocks_kiosk';

    /**
     * Lifetime of the cookie in minutes. Browsers cap cookies at 400 days.
     *
     * @var int
     */
    public const COOKIE_LIFETIME = 60 * 24 * 400;

    /**
     * The last usage is only saved once per this amount of seconds, so not every kiosk request writes to the database.
     *
     * @var int
     */
    private const LAST_USED_INTERVAL = 60;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'token',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'token',
    ];

    /**
     * Attributes to exclude from the audit.
     *
     * @var list<string>
     */
    protected $auditExclude = [
        'token',
        'last_used_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * Generate a new random token.
     */
    public static function generateToken(): string
    {
        return Str::random(64);
    }

    /**
     * Find the device that belongs to the value of the kiosk cookie ("<id>|<token>").
     */
    public static function findByCookie(?string $cookie): ?self
    {
        [$id, $token] = array_pad(explode('|', (string) $cookie, 2), 2, '');

        if (! ctype_digit($id) || $token === '') {
            return null;
        }

        $device = self::find((int) $id);

        return $device && $device->isTokenValid($token) ? $device : null;
    }

    /**
     * Check if the given token matches the token of the device (timing safe).
     */
    public function isTokenValid(string $token): bool
    {
        return hash_equals($this->token, $token);
    }

    /**
     * Get the value of the kiosk cookie for this device.
     */
    public function cookieValue(): string
    {
        return $this->id.'|'.$this->token;
    }

    /**
     * Get the url that pairs a tablet with this device.
     */
    public function pairingUrl(): string
    {
        return route('kiosk.pair', ['device' => $this->id, 'token' => $this->token]);
    }

    /**
     * Get the pairing url as qr code (inline svg), so a tablet can scan it.
     */
    public function pairingQrCodeSvg(): string
    {
        return QrCode::svg($this->pairingUrl());
    }

    /**
     * Save the last usage of the device, at most once per interval.
     */
    public function touchLastUsed(): void
    {
        if ($this->last_used_at && $this->last_used_at->diffInSeconds(now()) < self::LAST_USED_INTERVAL) {
            return;
        }

        // without timestamps and audit, the last usage is no change of the device
        $this->timestamps = false;
        self::withoutAuditing(fn () => $this->forceFill(['last_used_at' => now()])->save());
        $this->timestamps = true;
    }

    /**
     * {@inheritDoc}
     */
    public static function auditTypeLabel(): string
    {
        return 'Kiosk-Gerät';
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
        ];
    }
}
