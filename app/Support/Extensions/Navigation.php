<?php

namespace App\Support\Extensions;

use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Closure;

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
     * @var list<array{section: string, title: string, route: string, icon: string, permission: string|null, module: string|null, mobile: bool, order: int, group: string|null, badge: Closure|null}>
     */
    private array $entries = [];

    /**
     * Collapsible groups of entries by their key.
     *
     * @var array<string, array{section: string, title: string, icon: string, order: int}>
     */
    private array $groups = [];

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
     * @param  int  $order  position within the section (or the group), lower first
     * @param  string|null  $group  key of a group (addGroup) the entry is shown in
     * @param  Closure(): (array{text: string, variant: string}|null)|null  $badge  short status beside the title, e.g. "getrennt"; must be quick (no network)
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
        ?string $group = null,
        ?Closure $badge = null,
    ): void {
        $this->entries[] = compact('section', 'title', 'route', 'icon', 'permission', 'module', 'mobile', 'order', 'group', 'badge');
    }

    /**
     * Add a collapsible group to a section, e.g. "Einstellungen" with the small lists that are
     * rarely changed. Entries join it with add(..., group: <key>). A group without visible entries
     * is left out.
     */
    public function addGroup(string $section, string $key, string $title, string $icon, int $order = 100): void
    {
        $this->groups[$key] = compact('section', 'title', 'icon', 'order');
    }

    /**
     * Add a tile to the kiosk home page. The route must be a kiosk route (middleware "kiosk").
     */
    public function addKioskTile(string $title, string $route, string $icon, ?string $permission = null, ?string $module = null, int $order = 100): void
    {
        $this->kioskTiles[] = compact('title', 'route', 'icon', 'permission', 'module', 'order');
    }

    /**
     * Get the menu of a user: sections with their visible entries. An entry of a group has
     * "href" null and its entries in "children". Empty groups and sections are left out.
     *
     * @return list<array{key: string, title: string, entries: list<array{title: string, href: string|null, icon: string, mobile: bool, children?: list<array<string, mixed>>}>}>
     */
    public function forUser(User $user): array
    {
        $children = [];
        $entries = [];
        foreach ($this->visible($this->entries, $user) as $entry) {
            $item = [
                'title' => $entry['title'],
                'href' => route($entry['route'], absolute: false),
                'icon' => $entry['icon'],
                'mobile' => $entry['mobile'],
                'badge' => $entry['badge'] ? ($entry['badge'])() : null,
            ];
            if ($entry['group'] !== null && isset($this->groups[$entry['group']])) {
                $children[$entry['group']][] = $item;
            } else {
                $entries[] = ['section' => $entry['section'], 'order' => $entry['order'], 'item' => $item];
            }
        }

        // groups take their place in the section like an entry
        foreach ($children as $key => $groupEntries) {
            $group = $this->groups[$key];
            $entries[] = ['section' => $group['section'], 'order' => $group['order'], 'item' => [
                'title' => $group['title'], 'href' => null, 'icon' => $group['icon'], 'mobile' => false, 'children' => $groupEntries,
            ]];
        }
        usort($entries, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        $sections = [];
        foreach ($entries as $entry) {
            $sections[$entry['section']][] = $entry['item'];
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
