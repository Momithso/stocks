<?php

namespace Modules\Example;

use App\Support\Extensions\DashboardTiles;
use App\Support\Extensions\Navigation;
use App\Support\Extensions\SurfaceRoutes;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Connects the module to the core: routes, migrations and the extension points.
 *
 * The provider is always registered, also while the module is switched off. Everything that
 * belongs to the module carries its key ("example"), so the core hides it while it is off.
 */
class ExampleServiceProvider extends ServiceProvider
{
    /**
     * Key of the module, as in module.json.
     *
     * @var string
     */
    public const KEY = 'example';

    /**
     * Bootstrap the module.
     */
    public function boot(Navigation $navigation, DashboardTiles $dashboardTiles, SurfaceRoutes $surfaceRoutes): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        Route::middleware(['web', 'auth', 'module:'.self::KEY])->group(__DIR__.'/routes/web.php');
        // the same page on the kiosk, only while the kiosk module is on
        $surfaceRoutes->add('kiosk', __DIR__.'/routes/kiosk.php', self::KEY);

        $navigation->add('overview', 'Beispiel', 'example.index', 'flask', 'example.view', self::KEY, order: 90);
        $navigation->addKioskTile('Beispiel', 'kiosk.example.index', 'flask', 'example.view', self::KEY);
        $dashboardTiles->add('Example::DashboardTile', 'example.view', self::KEY);
    }
}
