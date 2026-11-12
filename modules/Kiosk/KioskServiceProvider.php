<?php

namespace Modules\Kiosk;

use App\Support\Audit\AuditSubjects;
use App\Support\Extensions\DemoData;
use App\Support\Extensions\Navigation;
use App\Support\Extensions\SurfaceRoutes;
use App\Support\Settings;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Kiosk\Http\Middleware\EnsureKioskDevice;
use Modules\Kiosk\Http\Middleware\EnsureKioskUnlocked;
use Modules\Kiosk\Models\KioskDevice;
use Modules\Kiosk\Support\KioskDemo;

/**
 * Kiosk module: paired tablets with lock screen and pin. The kiosk is a surface (see
 * App\Support\Surface): routes of the core and the modules run again under /kiosk with the kiosk
 * layout, for the person that unlocked it. See documentation/KIOSK.md.
 */
class KioskServiceProvider extends ServiceProvider
{
    /**
     * Key of the module, as in module.json.
     *
     * @var string
     */
    public const KEY = 'kiosk';

    /**
     * Bootstrap the module.
     */
    public function boot(Router $router, Navigation $navigation, SurfaceRoutes $surfaceRoutes, AuditSubjects $auditSubjects, Settings $settings, DemoData $demoData): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        $router->aliasMiddleware('kiosk.device', EnsureKioskDevice::class);
        $router->aliasMiddleware('kiosk', EnsureKioskUnlocked::class);

        Route::middleware(['web', 'module:'.self::KEY])->group(__DIR__.'/routes/web.php');

        // routes of the core on the kiosk; other modules add theirs the same way
        $surfaceRoutes->add(self::KEY, __DIR__.'/routes/kiosk.php');

        $settings->addDefaults([
            'kiosk.session_timeout' => config('kiosk.session_timeout'),
            'kiosk.lock_after_task' => false,
        ]);

        $navigation->add('admin', 'Kiosk-Geräte', 'admin.kiosk-devices.index', 'tablet-screen-button', 'kiosk.devices.manage', self::KEY, order: 60, group: 'settings');
        $navigation->addKioskTile('Scannen und umlagern', 'kiosk.inventory.scan', 'qrcode', 'inventory.view', self::KEY, order: 40,
            description: 'Code scannen: nachsehen, wo etwas hingehört, umlagern, Zustand ändern, Etikett drucken.', section: 'Lager');
        $auditSubjects->register(KioskDevice::class);
        $demoData->add(KioskDemo::class, self::KEY, order: 60);

        // all route files for the kiosk, registered once every provider has added its own
        $this->app->booted(function () use ($surfaceRoutes) {
            foreach ($surfaceRoutes->filesFor(self::KEY) as $entry) {
                // switched off modules answer 404 before the kiosk asks for the device or the person
                Route::middleware(['web', 'module:'.self::KEY, ...($entry['module'] ? ['module:'.$entry['module']] : []), 'kiosk.device', 'kiosk'])
                    ->prefix('kiosk')
                    ->name('kiosk.')
                    ->group($entry['file']);
            }
        });
    }
}
