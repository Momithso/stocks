<?php

namespace App\Support\Extensions;

use App\Models\User;
use App\Support\Modules\ModuleRegistry;

/**
 * Extension point for menu entries.
 *
 * The core and the modules add their entries while booting. The server sends the menu of the
 * current user to the browser, where the sidebar, the bar at the bottom of phones and the kiosk
 * show the same entries. Entries are grouped by task, never by role, and a user only sees the
 * entries he has the permission for.
 */
class Navigation
{
    /**
     * Sections of the menu in their order.
     *
     * @var array<string, string>
     */
    public const SECTIONS = [
        'overview' => 'Übersicht',
        'inventory' => 'Inventar',
        'loans' => 'Verleih',
        'admin' => 'Verwaltung',
    ];

    /**
     * @var list<array{section: string, title: string, route: string, icon: string, permission: string|null, module: string|null, mobile: bool, order: int}>
     */
    private array $entries = [];

    /**
     * @var list<array{title: string, route: string, icon: string, permission: string|null, module: string|null, order: int}>
     */
    private array $kioskTiles = [];

    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * Add an entry to the menu.
     *
     * @param  string  $section  key of SECTIONS
     * @param  string  $route  name of the route the entry links to
     * @param  string  $icon  name of a Font Awesome solid icon
     * @param  string|null  $permission  permission the user needs to see the entry
     * @param  string|null  $module  key of the module the entry belongs to, hidden while the module is off
     * @param  bool  $mobile  show the entry in the bar at the bottom of phones (at most four entries fit)
     * @param  int  $order  position within the section, lower first
     */
    public function add(
        string $section,
        string $title,
        string $route,
        string $icon,
        ?string $permission = null,
        ?string $module = null,
        bool $mobile = false,
        int $order = 100,
    ): void {
        $this->entries[] = compact('section', 'title', 'route', 'icon', 'permission', 'module', 'mobile', 'order');
    }

    /**
     * Add a tile to the kiosk home page. The route must be a kiosk route (middleware "kiosk").
     */
    public function addKioskTile(string $title, string $route, string $icon, ?string $permission = null, ?string $module = null, int $order = 100): void
    {
        $this->kioskTiles[] = compact('title', 'route', 'icon', 'permission', 'module', 'order');
    }

    /**
     * Get the menu of a user: sections with their visible entries. Empty sections are left out.
     *
     * @return list<array{key: string, title: string, entries: list<array{title: string, href: string, icon: string, mobile: bool}>}>
     */
    public function forUser(User $user): array
    {
        $sections = [];
        foreach ($this->visible($this->entries, $user) as $entry) {
            $sections[$entry['section']][] = [
                'title' => $entry['title'],
                'href' => route($entry['route'], absolute: false),
                'icon' => $entry['icon'],
                'mobile' => $entry['mobile'],
            ];
        }

        $menu = [];
        foreach (self::SECTIONS as $key => $title) {
            if (isset($sections[$key])) {
                $menu[] = ['key' => $key, 'title' => $title, 'entries' => $sections[$key]];
            }
        }

        return $menu;
    }

    /**
     * Get the kiosk tiles of a user that unlocked the kiosk.
     *
     * @return list<array{title: string, href: string, icon: string}>
     */
    public function kioskTilesForUser(User $user): array
    {
        return array_map(
            fn (array $tile) => ['title' => $tile['title'], 'href' => route($tile['route'], absolute: false), 'icon' => $tile['icon']],
            $this->visible($this->kioskTiles, $user),
        );
    }

    /**
     * Entries of the core and of active modules the user has the permission for, sorted by order.
     *
     * @template T of array{permission: string|null, module: string|null, order: int}
     *
     * @param  list<T>  $entries
     * @return list<T>
     */
    private function visible(array $entries, User $user): array
    {
        $entries = array_filter($entries, fn (array $entry) => ($entry['module'] === null || $this->modules->isActive($entry['module']))
            && ($entry['permission'] === null || $user->can($entry['permission'])));

        usort($entries, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return array_values($entries);
    }
}
