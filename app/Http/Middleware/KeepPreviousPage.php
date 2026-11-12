<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Json requests of a page (live update, lookups, chat) are background requests. Laravel remembers
 * every other GET request as the "previous page" for back(); a poll would then make the next
 * redirect of a form land on json instead of the page ("All Inertia requests must receive a valid
 * Inertia response"). Marked as ajax, they are not remembered. Runs before the session starts.
 */
class KeepPreviousPage
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && $request->wantsJson() && ! $request->ajax()) {
            $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        }

        return $next($request);
    }
}
