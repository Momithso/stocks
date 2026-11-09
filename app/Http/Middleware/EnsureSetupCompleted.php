<?php

namespace App\Http\Middleware;

use App\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Send every request to the setup wizard until the instance is set up.
 */
class EnsureSetupCompleted
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('setup.*') && ! $this->settings->isSetupCompleted()) {
            return redirect()->route('setup.show');
        }

        return $next($request);
    }
}
