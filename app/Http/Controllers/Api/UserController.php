<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

class UserController extends Controller
{
    public function getOnlineStatus(Request $request)
    {
        $request->validate([
            'user_ids' => 'required|array',
            'user_ids.*' => 'integer|exists:users,id'
        ]);

        $statuses = [];

        foreach ($request->user_ids as $userId) {
            $user = User::find($userId);

            if ($user) {
                $statuses[] = [
                    'user_id' => $user->id,
                    'is_online' => $user->isOnline(),
                    'last_seen' => $user->last_seen_at?->toISOString(),
                ];
            }
        }

        return response()->json([
            'statuses' => $statuses
        ]);
    }

    public function updateActivity(Request $request)
    {
        $user = Auth::user();

        if ($user) {
            $user->updateLastSeen();

            return response()->json([
                'message' => 'Activity updated',
                'last_seen' => $user->last_seen_at,
                'is_online' => true
            ]);
        }

        return response()->json(['message' => 'User not found'], 404);
    }
}