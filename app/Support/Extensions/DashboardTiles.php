<?php

namespace App\Support\Extensions;

use App\Models\User;
use App\Support\Modules\ModuleRegistry;

/**
 * Extension point for tiles on the dashboard.
 *
 * A tile is a Vue component. Components of modules are named "<Module>::<Component>" and live in
 * modules/<Module>/resources/js/components (see resources/js/composables/useModuleComponent.ts).
 */
class DashboardTiles
{
    /**
     * @var list<array{component: string, permission: string|null, module: string|null, order: int}>
     */
    private array $tiles = [];

    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * Add a tile to the dashboard.
     *
     * @param  string  $component  e.g. "Example::DashboardTile"
     * @param  string|null  $permission  permission the user needs to see the tile
     * @param  string|null  $module  key of the module the tile belongs to, hidden while the module is off
     */
    public function add(string $component, ?string $permission = null, ?string $module = null, int $order = 100): void
    {
        $this->tiles[] = compact('component', 'permission', 'module', 'order');
    }

    /**
     * Get the components of the tiles the user may see, in their order.
     *
     * @return list<string>
     */
    public function forUser(User $user): array
    {
        $tiles = array_filter($this->tiles, fn (array $tile) => ($tile['module'] === null || $this->modules->isActive($tile['module']))
            && ($tile['permission'] === null || $user->can($tile['permission'])));

        usort($tiles, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return array_column($tiles, 'component');
    }
}
