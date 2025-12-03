<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\User;

class UpdateUserActivity
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Update user activity if authenticated
        if ($request->user()) {
            $request->user()->update([
                'last_seen_at' => now()
            ]);

            // Also update UserActivityLog for detailed tracking
            \App\Models\UserActivityLog::updateOrCreate(
                ['user_id' => $request->user()->id],
                ['last_seen' => now(), 'status' => 'online']
            );
        }

        return $response;
    }
}
