<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Settings of the instance, stored in the table "settings".
 *
 * Every key has a default here, so an empty table works. Values are cached as one array and the
 * cache is cleared on every change, so reading a setting does not query the database.
 */
class Settings
{
    /**
     * Cache key of all stored settings.
     *
     * @var string
     */
    private const CACHE_KEY = 'stocks.settings';

    /**
     * Default values of all settings of the core.
     *
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        'organization.name' => 'Stocks',
        'organization.short_name' => 'Stocks',
        // accent colour of buttons and links, the mint of Portals
        'organization.accent_color' => '#22948c',
        // paths of logo and favicon on S3, see App\Support\Branding
        'organization.logo_path' => null,
        'organization.favicon_path' => null,
        'legal.imprint_url' => null,
        'legal.privacy_url' => null,
        'legal.contact_email' => null,
        'mail.from_name' => null,
        'mail.from_address' => null,
        // prefix of new item numbers, empty for the short name (see App\Support\Inventory\ItemNumbers)
        'inventory.number_prefix' => null,
        'setup.completed_at' => null,
    ];

    /**
     * Get a setting, or its default.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $stored = $this->stored();

        if (array_key_exists($key, $stored)) {
            return $stored[$key];
        }

        return self::DEFAULTS[$key] ?? $default;
    }

    /**
     * Store several settings at once.
     *
     * @param  array<string, mixed>  $values
     */
    public function set(array $values): void
    {
        foreach ($values as $key => $value) {
            // updated one by one, so every change is audited
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Get all settings of the core with their current values.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_merge(self::DEFAULTS, array_intersect_key($this->stored(), self::DEFAULTS));
    }

    /**
     * Check if the setup was completed.
     */
    public function isSetupCompleted(): bool
    {
        return $this->get('setup.completed_at') !== null;
    }

    /**
     * Get the stored settings from the cache or the database.
     *
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => Setting::pluck('value', 'key')->all());
    }
}
