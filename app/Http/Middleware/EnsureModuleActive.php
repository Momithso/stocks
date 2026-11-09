<?php

namespace App\Http\Middleware;

use App\Support\Modules\ModuleRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware "module:<key>": routes of a module that is switched off answer 404.
 */
class EnsureModuleActive
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless($this->modules->isActive($module), 404);

        return $next($request);
    }
}
