<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorIsEnabled
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tfaRequired = config('privacy.require_two_factor');
        $tfaEnabled = $request->user()?->hasEnabledTwoFactorAuthentication();

        if ($tfaRequired && $request->user() && !$tfaEnabled) {
            return Redirect::route('security.edit');
        }

        return $next($request);
    }
}
