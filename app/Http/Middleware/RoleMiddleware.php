<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        // Check if user has any of the required roles
        $hasRole = false;
        foreach ($roles as $role) {
            if ($role === 'admin' && $user->isAdmin()) {
                $hasRole = true;
                break;
            } elseif ($role === 'vendor' && $user->isVendor()) {
                $hasRole = true;
                break;
            } elseif ($role === 'customer' && $user->isCustomer()) {
                $hasRole = true;
                break;
            } elseif ($role === 'delivery' && $user->isDelivery()) {
                $hasRole = true;
                break;
            }
        }

        if (!$hasRole) {
            Log::warning('Role access denied', [
                'user_id' => $user->id,
                'user_role_id' => $user->role_id,
                'required_roles' => $roles,
                'route' => $request->route() ? $request->route()->getName() : null
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Forbidden – insufficient role'
            ], 403);
        }

        return $next($request);
    }
}
