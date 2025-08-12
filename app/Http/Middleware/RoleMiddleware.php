<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        // Check if the user is authenticated and has the required role
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Check if the user has a role
        if (!$user->role) {
            return response()->json(['message' => 'User has no role assigned'], 403);
        }

        // Check if the user has the required role
        if (! $user || ! in_array($user->role->name, $roles)) {
            return response()->json(['message' => 'Forbidden – insufficient role'], 403);
        }

        return $next($request);
    }
}
