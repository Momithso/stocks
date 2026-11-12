<?php

namespace App\Support;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * The surface a request runs on, e.g. the kiosk of the module "kiosk". Without a surface Stocks
 * behaves as on the pc and the phone.
 *
 * A surface reuses routes, controllers and pages of the core and the modules under its own prefix
 * and layout. The module that owns it sets it as request attribute in a middleware; the core only
 * reads it, so it does not know the module. Everything hangs on the running request, so nothing
 * survives a request under Octane.
 *
 * Keys of a surface: name, base (path prefix, e.g. "/kiosk"), route_prefix (e.g. "kiosk."),
 * layout (e.g. "Kiosk::KioskLayout"), done_route (target after a finished task), manifest and
 * whatever the module shares with its pages (see SHARED).
 */
class Surface
{
    /**
     * Name of the request attribute.
     *
     * @var string
     */
    public const ATTRIBUTE = 'surface';

    /**
     * Keys that pages get as shared prop "surface"; the rest stays on the server.
     *
     * @var list<string>
     */
    private const SHARED = ['name', 'base', 'layout', 'platform', 'direct_print', 'person', 'expires_in', 'lock_after_task'];

    /**
     * Set or extend the surface of a request.
     *
     * @param  array<string, mixed>  $surface
     */
    public static function set(Request $request, array $surface): void
    {
        $request->attributes->set(self::ATTRIBUTE, [...($request->attributes->get(self::ATTRIBUTE) ?? []), ...$surface]);
    }

    /**
     * Surface of the current request, null on the pc and the phone.
     *
     * @return array<string, mixed>|null
     */
    public static function current(): ?array
    {
        return request()->attributes->get(self::ATTRIBUTE);
    }

    /**
     * Part of the surface the pages see, null without surface.
     *
     * @return array<string, mixed>|null
     */
    public static function shared(): ?array
    {
        $surface = self::current();

        return $surface ? array_intersect_key($surface, array_flip(self::SHARED)) : null;
    }

    /**
     * Check if a route can be reached on the current surface (on the pc: if it exists).
     */
    public static function has(string $name): bool
    {
        $prefix = self::current()['route_prefix'] ?? null;

        return Route::has($prefix ? $prefix.$name : $name);
    }

    /**
     * Url of a route on the current surface: "loans.issue" becomes "kiosk.loans.issue" on the
     * kiosk, if the kiosk has it; otherwise the normal route.
     */
    public static function route(string $name, mixed $parameters = [], bool $absolute = false): string
    {
        $prefix = self::current()['route_prefix'] ?? null;

        if ($prefix && Route::has($prefix.$name)) {
            return route($prefix.$name, $parameters, $absolute);
        }

        return route($name, $parameters, $absolute);
    }

    /**
     * Url of a route if it can be reached on the current surface, otherwise null: links to pages
     * the kiosk does not have (e.g. the detail page of an item) are left out there.
     */
    public static function link(string $name, mixed $parameters = []): ?string
    {
        return self::has($name) ? self::route($name, $parameters) : null;
    }

    /**
     * Target after a finished task (issue, return, inspection): the done route of the surface,
     * which e.g. locks the kiosk, otherwise the given redirect.
     */
    public static function finish(RedirectResponse $default): RedirectResponse
    {
        $done = self::current()['done_route'] ?? null;

        return $done ? redirect()->route($done) : $default;
    }
}
