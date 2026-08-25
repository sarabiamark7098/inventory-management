<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard: `->middleware('role:ADMIN,MANAGER')`.
 *
 * Deactivating an account also has to lock it out immediately, so this checks
 * `is_active` on every request rather than trusting the token alone.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['error' => 'Authentication required.'], 401);
        }

        if (! $user->is_active) {
            return response()->json(['error' => 'This account has been deactivated.'], 403);
        }

        if ($roles !== [] && ! in_array($user->role, $roles, true)) {
            return response()->json([
                'error' => 'Your role does not permit this action.',
            ], 403);
        }

        return $next($request);
    }
}
