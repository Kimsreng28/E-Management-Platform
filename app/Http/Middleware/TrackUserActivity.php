<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class TrackUserActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Track authenticated user activity
        if (Auth::check()) {
            $user = Auth::user();

            // Only update every minute to reduce database writes
            if (!$user->last_seen_at || $user->last_seen_at->diffInMinutes(now()) >= 1) {
                $user->updateLastSeen();
            }
        }

        return $response;
    }
}