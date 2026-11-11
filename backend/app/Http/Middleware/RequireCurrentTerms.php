<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `terms` - nobody reaches a brand's data until they've agreed to the current
 * terms and privacy policy (config dealerdock.legal.version): the owner at
 * sign-up, invited staff and dealers at first sign-in, everyone again when
 * the version changes. 403 with code terms_not_accepted; the app shows the
 * "please agree" page.
 */
class RequireCurrentTerms
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->terms_version !== config('dealerdock.legal.version')) {
            return response()->json([
                'message' => 'Please read and agree to the current terms and privacy policy to continue.',
                'code' => 'terms_not_accepted',
            ], 403);
        }

        return $next($request);
    }
}
