<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Jenssegers\Agent\Agent;
use Illuminate\Support\Facades\DB;

class SecurityController extends Controller
{
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'new_password' => 'required|min:8|confirmed',
        ]);

        $user = Auth::user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Current password is incorrect'], 422);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json(['message' => 'Password changed successfully']);
    }

    public function toggleTwoFactor(Request $request)
    {
        $request->validate([
            'enable' => 'required|boolean',
        ]);

        $user = Auth::user();

        $user->two_factor_enabled = $request->enable;
        $user->save();

        return response()->json([
            'message' => 'Two-factor authentication ' . ($request->enable ? 'enabled' : 'disabled'),
            'enabled' => $user->two_factor_enabled,
        ]);
    }

    public function getSessions()
    {
        $user = Auth::user();
        $agent = new Agent();
        $sessions = [];

        // Get sessions from database where user_id matches or try to match by IP if user_id is null
        $dbSessions = DB::table('sessions')
            ->where(function($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->orWhere('ip_address', request()->ip());
            })
            ->orderBy('last_activity', 'desc')
            ->get();

        foreach ($dbSessions as $session) {
            $agent->setUserAgent($session->user_agent);

            $sessions[] = [
                'id' => $session->id,
                'device' => $agent->device() ?: 'Unknown Device',
                'browser' => $agent->browser(),
                'platform' => $agent->platform(),
                'ip_address' => $session->ip_address,
                'last_activity' => date('Y-m-d H:i:s', $session->last_activity),
                'is_current' => $session->id === session()->getId(),
            ];
        }

        return response()->json(['sessions' => $sessions]);
    }

    public function revokeSession($id)
    {
        $user = Auth::user();

        // Verify the session belongs to the current user
        $session = DB::table('sessions')
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$session) {
            return response()->json(['message' => 'Session not found'], 404);
        }

        if ($session->id === session()->getId()) {
            return response()->json(['message' => 'Cannot revoke current session'], 422);
        }

        DB::table('sessions')->where('id', $id)->delete();

        return response()->json(['message' => 'Session revoked']);
    }
}
