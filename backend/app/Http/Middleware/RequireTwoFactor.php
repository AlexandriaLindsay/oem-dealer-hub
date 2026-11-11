<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Blocks tenant data until the user has set up two-factor sign-in. The frontend sends them to setup on this code. */
class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('dealerdock.require_two_factor') && ! $request->user()?->hasTwoFactor()) {
            return response()->json([
                'message' => 'Set up two-factor sign-in to continue.',
                'code' => 'two_factor_setup_required',
            ], 403);
        }

        return $next($request);
    }
}
