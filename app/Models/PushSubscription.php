<?php

namespace App\Models;

use Illuminate\Support\Carbon;
use NotificationChannels\WebPush\PushSubscription as BasePushSubscription;

/**
 * Push subscription of a device: of an account, or of the borrower page of a loan. The browser
 * creates it after the permission; the push service reports it gone when it is no longer valid.
 *
 * @property string|null $device
 * @property Carbon|null $last_used_at
 * @property Carbon $created_at
 */
class PushSubscription extends BasePushSubscription
{
    /**
     * @var list<string>
     */
    protected $fillable = ['endpoint', 'public_key', 'auth_token', 'content_encoding', 'device', 'last_used_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [...parent::casts(), 'last_used_at' => 'datetime'];
    }

    /**
     * Data for the list of devices.
     *
     * @return array{id: int, device: string, created_at: string, last_used_at: string|null}
     */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'device' => $this->device ?: 'Unbekanntes Gerät',
            'created_at' => $this->created_at->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
        ];
    }
}
