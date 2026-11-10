<?php

namespace App\Http\Middleware;

use App\Support\Branding;
use App\Support\Extensions\Navigation;
use App\Support\Settings;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Props every page gets. All props are closures, so partial reloads (e.g. infinite scroll of a
 * table) do not compute them.
 */
class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Defines the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $settings = app(Settings::class);

        return [
            ...parent::share($request),
            'auth' => fn () => $request->user() ? [
                'user' => $request->user()->only(['id', 'firstname', 'lastname', 'email', 'name']),
                'permissions' => $request->user()->getAllPermissions()->pluck('name'),
            ] : null,
            'navigation' => fn () => $request->user() ? app(Navigation::class)->forUser($request->user()) : [],
            'organization' => fn () => [
                'name' => $settings->get('organization.name'),
                'short_name' => $settings->get('organization.short_name'),
                'accent_color' => $settings->get('organization.accent_color'),
                'logo_url' => app(Branding::class)->url('logo'),
                'imprint_url' => $settings->get('legal.imprint_url'),
                'privacy_url' => $settings->get('legal.privacy_url'),
            ],
            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'warning' => $request->session()->get('warning'),
                'info' => $request->session()->get('info'),
                // item with a new number (e.g. split off part): offer to print its label
                'label' => $request->session()->get('label'),
            ],
            // start time of the live update of tables, see App\Support\Table\Table
            'serverTime' => fn () => now()->timestamp,
        ];
    }
}
