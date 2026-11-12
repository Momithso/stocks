<?php

namespace App\Http\Middleware;

use App\Support\MailSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uses the mail server of the settings for mails of this request, see App\Support\MailSettings.
 */
class ApplyMailSettings
{
    public function __construct(private readonly MailSettings $mailSettings) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->mailSettings->apply();

        return $next($request);
    }
}
