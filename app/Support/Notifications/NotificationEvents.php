<?php

namespace App\Support\Notifications;

use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Closure;

/**
 * Extension point for notification events. The core and the modules register what they notify
 * about while booting; the tables of settings (profile, borrower page) and the channels of every
 * notification (App\Notifications\StocksNotification) come from here. See
 * documentation/NOTIFICATIONS.md.
 *
 * Settings store only deviations from the defaults: {"loans.chat_staff": {"push": false}}.
 */
class NotificationEvents
{
    /**
     * Channels a person can choose, with their German name.
     *
     * @var array<string, string>
     */
    public const CHANNELS = ['mail' => 'E-Mail', 'push' => 'Push'];

    /**
     * Who gets an event: members (accounts) or contacts (borrower page of a loan).
     */
    public const MEMBERS = 'members';

    public const CONTACTS = 'contacts';

    /**
     * @var array<string, array{key: string, label: string, group: string, audience: string, permission: string|null, module: string|null, defaults: array<string, bool>, required_mail: bool, description: string|null}>
     */
    private array $events = [];

    /**
     * While true nothing is notified (demo data, see quietly()).
     */
    private static bool $muted = false;

    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * Run something without notifying anybody, e.g. the demo generator. Senders of notifications
     * check isMuted() before they send or queue; a listener of the core stops the rest.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function quietly(Closure $callback): mixed
    {
        $before = self::$muted;
        self::$muted = true;
        try {
            return $callback();
        } finally {
            self::$muted = $before;
        }
    }

    /**
     * Check if notifications are switched off at the moment (quietly()).
     */
    public static function isMuted(): bool
    {
        return self::$muted;
    }

    /**
     * Register an event.
     *
     * @param  string  $key  e.g. "loans.chat_staff"
     * @param  string  $audience  self::MEMBERS or self::CONTACTS
     * @param  string|null  $permission  members only see (and get) it with this permission
     * @param  array<string, bool>  $defaults  channel => on, for channels that are not set
     * @param  bool  $requiredMail  always sent by mail, the mail can not be switched off
     */
    public function add(
        string $key,
        string $label,
        string $group,
        string $audience,
        ?string $permission = null,
        ?string $module = null,
        array $defaults = ['mail' => true, 'push' => true],
        bool $requiredMail = false,
        ?string $description = null,
    ): void {
        $this->events[$key] = [
            'key' => $key,
            'label' => $label,
            'group' => $group,
            'audience' => $audience,
            'permission' => $permission,
            'module' => $module,
            'defaults' => [...['mail' => true, 'push' => true], ...$defaults],
            'required_mail' => $requiredMail,
            'description' => $description,
        ];
    }

    /**
     * Get an event, null if it is unknown or its module is off.
     *
     * @return array{key: string, label: string, group: string, audience: string, permission: string|null, module: string|null, defaults: array<string, bool>, required_mail: bool, description: string|null}|null
     */
    public function get(string $key): ?array
    {
        $event = $this->events[$key] ?? null;

        return $event && ($event['module'] === null || $this->modules->isActive($event['module'])) ? $event : null;
    }

    /**
     * Events of an audience whose module is on; for members only those the account may get.
     *
     * @return list<array{key: string, label: string, group: string, audience: string, permission: string|null, module: string|null, defaults: array<string, bool>, required_mail: bool, description: string|null}>
     */
    public function for(string $audience, ?User $user = null): array
    {
        return array_values(array_filter(
            array_map(fn (array $event) => $this->get($event['key']), $this->events),
            fn (?array $event) => $event !== null && $event['audience'] === $audience
                && ($event['permission'] === null || $user === null || $user->can($event['permission'])),
        ));
    }

    /**
     * Check if a channel of an event is on: the stored choice, else the default. A required mail is
     * always on; an unknown event is off.
     *
     * @param  array<string, array<string, bool>>|null  $preferences  stored deviations
     */
    public function wants(?array $preferences, string $key, string $channel): bool
    {
        $event = $this->get($key);
        if ($event === null) {
            return false;
        }
        if ($channel === 'mail' && $event['required_mail']) {
            return true;
        }

        return (bool) ($preferences[$key][$channel] ?? $event['defaults'][$channel] ?? false);
    }

    /**
     * Table of settings for the frontend: one row per event with the state of every channel.
     *
     * @param  array<string, array<string, bool>>|null  $preferences
     * @return list<array{key: string, label: string, group: string, description: string|null, required_mail: bool, channels: array<string, bool>}>
     */
    public function table(string $audience, ?array $preferences, ?User $user = null): array
    {
        return array_map(fn (array $event) => [
            'key' => $event['key'],
            'label' => $event['label'],
            'group' => $event['group'],
            'description' => $event['description'],
            'required_mail' => $event['required_mail'],
            'channels' => array_map(fn (string $channel) => $this->wants($preferences, $event['key'], $channel), array_combine(array_keys(self::CHANNELS), array_keys(self::CHANNELS))),
        ], $this->for($audience, $user));
    }

    /**
     * Turn the choices of a form into the deviations to store. Unknown events and channels are
     * ignored, a required mail is never stored as off.
     *
     * @param  array<string, array<string, mixed>>  $choices  event => channel => bool
     * @return array<string, array<string, bool>>|null
     */
    public function deviations(string $audience, array $choices, ?User $user = null): ?array
    {
        $stored = [];
        foreach ($this->for($audience, $user) as $event) {
            foreach (array_keys(self::CHANNELS) as $channel) {
                if (! array_key_exists($channel, $choices[$event['key']] ?? []) || ($channel === 'mail' && $event['required_mail'])) {
                    continue;
                }
                $on = (bool) $choices[$event['key']][$channel];
                if ($on !== $event['defaults'][$channel]) {
                    $stored[$event['key']][$channel] = $on;
                }
            }
        }

        return $stored ?: null;
    }
}
