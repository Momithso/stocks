<?php

namespace App\Providers;

use App\Models\Invitation;
use App\Models\KioskDevice;
use App\Models\Module;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Audit\AuditSubjects;
use App\Support\Extensions\DashboardTiles;
use App\Support\Extensions\Navigation;
use App\Support\Modules\ModuleRegistry;
use App\Support\Settings;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Mime\Address;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register the services of the core and the service providers of all modules.
     *
     * The registries are singletons: they are filled once while booting and hold no request
     * state, so they are safe with Octane.
     */
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class, fn () => new ModuleRegistry(base_path('modules')));
        $this->app->singleton(Navigation::class);
        $this->app->singleton(DashboardTiles::class);
        $this->app->singleton(AuditSubjects::class);
        $this->app->singleton(Settings::class);

        foreach ($this->app->make(ModuleRegistry::class)->all() as $module) {
            $this->app->register($module->provider);
        }

        if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(Navigation $navigation, AuditSubjects $auditSubjects): void
    {
        if (config('app.force_https')) {
            URL::forceScheme('https');
        }

        // long passwords are safer and easier to remember than short ones with special characters;
        // the check against leaked passwords asks an external service and is off by default
        Password::defaults(fn () => Password::min(12)->when(config('stocks.check_leaked_passwords'), fn (Password $rule) => $rule->uncompromised()));

        foreach ([User::class, Role::class, Invitation::class, Module::class, Setting::class, KioskDevice::class] as $class) {
            $auditSubjects->register($class);
        }

        // the sender of the settings replaces MAIL_FROM_ADDRESS of the environment, if it is set
        Event::listen(MessageSending::class, function (MessageSending $event) {
            $settings = app(Settings::class);
            if ($address = $settings->get('mail.from_address')) {
                $event->message->from(new Address($address, (string) ($settings->get('mail.from_name') ?? $settings->get('organization.name'))));
            }
        });

        $this->registerNavigation($navigation);
    }

    /**
     * Menu entries of the core.
     */
    private function registerNavigation(Navigation $navigation): void
    {
        $navigation->add('overview', 'Dashboard', 'dashboard', 'house', mobile: true, order: 0);

        $navigation->add('admin', 'Konten', 'admin.users.index', 'users', 'users.view', order: 10);
        $navigation->add('admin', 'Rollen', 'admin.roles.index', 'user-shield', 'roles.manage', order: 20);
        $navigation->add('admin', 'Module', 'admin.modules.index', 'puzzle-piece', 'modules.manage', order: 30);
        $navigation->add('admin', 'Kiosk-Geräte', 'admin.kiosk-devices.index', 'tablet-screen-button', 'kiosk.devices.manage', order: 40);
        $navigation->add('admin', 'Einstellungen', 'admin.settings.edit', 'gear', 'settings.manage', order: 50);
        $navigation->add('admin', 'Protokoll', 'admin.audit-log', 'clock-rotate-left', 'audit.view', order: 60);
        $navigation->add('admin', 'Styleguide', 'styleguide', 'palette', 'settings.manage', order: 70);
    }
}
