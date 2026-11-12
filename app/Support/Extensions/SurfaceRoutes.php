<?php

namespace App\Support\Extensions;

/**
 * Route files of the modules for a surface, e.g. modules/Loans/routes/kiosk.php for the kiosk.
 * Each file lists which routes of its module exist on the surface (a list of what is allowed:
 * what is not in it does not exist there). The module that owns the surface registers the files
 * under its prefix and middleware; without that module nobody registers them.
 *
 * Filled once while the application boots (service providers), so it holds no request state.
 */
class SurfaceRoutes
{
    /**
     * Route files by surface.
     *
     * @var array<string, list<array{file: string, module: string|null}>>
     */
    private array $files = [];

    /**
     * Add a route file for a surface. With a module key the routes answer 404 while that module
     * is switched off.
     */
    public function add(string $surface, string $file, ?string $module = null): void
    {
        $this->files[$surface][] = compact('file', 'module');
    }

    /**
     * Route files of a surface.
     *
     * @return list<array{file: string, module: string|null}>
     */
    public function filesFor(string $surface): array
    {
        return $this->files[$surface] ?? [];
    }
}
