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
        // logo for labels and pdf, see App\Support\Branding
        'organization.print_logo_path' => null,
        // postal address, lines separated by line breaks (letterhead of the box contents pdf)
        'organization.address' => null,
        'legal.imprint_url' => null,
        'legal.privacy_url' => null,
        'legal.contact_email' => null,
        'mail.from_name' => null,
        'mail.from_address' => null,
        // mail server, see App\Support\MailSettings; empty host: the environment (MAIL_*) applies
        'mail.host' => null,
        'mail.port' => null,
        'mail.username' => null,
        // stored encrypted
        'mail.password' => null,
        // "ssl" (port 465) or "tls" (STARTTLS, port 587)
        'mail.encryption' => null,
        // prefix of new item numbers, empty for the short name (see App\Support\Inventory\ItemNumbers)
        'inventory.number_prefix' => null,
        // address in the codes of labels, empty for APP_URL (see App\Support\Labels\ShortLink)
        'labels.base_url' => null,
        // address that was confirmed before the first print; a hint shows while it differs
        'labels.base_url_confirmed' => null,
        'setup.completed_at' => null,
        // time the demo data was generated, null in a real instance (see App\Support\Demo\DemoGenerator)
        'demo.seeded_at' => null,
        // codes of the test label sheet of the demo data, see App\Support\Demo\TestSheet
        'demo.labels' => [],
    ];

    /**
     * Defaults of settings of modules, added while booting (addDefaults()).
     *
     * @var array<string, mixed>
     */
    private array $moduleDefaults = [];

    /**
     * Get a setting, or its default.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $stored = $this->stored();

        if (array_key_exists($key, $stored)) {
            return $stored[$key];
        }

        return self::DEFAULTS[$key] ?? $this->moduleDefaults[$key] ?? $default;
    }

    /**
     * Add the defaults of a module's settings. Keys start with the key of the module, e.g.
     * "loans.buffer_hours". The values are stored in the same table as those of the core.
     *
     * @param  array<string, mixed>  $defaults
     */
    public function addDefaults(array $defaults): void
    {
        $this->moduleDefaults = [...$this->moduleDefaults, ...$defaults];
    }

    /**
     * Get the settings of a module (keys starting with "<module>.") with their current values.
     *
     * @return array<string, mixed>
     */
    public function module(string $module): array
    {
        $defaults = array_filter($this->moduleDefaults, fn (string $key) => str_starts_with($key, $module.'.'), ARRAY_FILTER_USE_KEY);

        return array_merge($defaults, array_intersect_key($this->stored(), $defaults));
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
