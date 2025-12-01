<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Models\UserNotificationSetting;
use Illuminate\Support\Facades\Auth;

class NotificationSettingsController extends Controller
{
    // Admin role
    private function isAdmin()
    {
        return Auth::check() && Auth::user()->role_id === 1;
    }

    // Vendor role
    private function isVendor()
    {
        return Auth::check() && Auth::user()->role_id === 4;
    }

    public function index()
    {
        $user = Auth::user();
        $settings = UserNotificationSetting::firstOrCreate(
            ['user_id' => $user->id],
            [
                'email' => true,
                'push' => true,
                'telegram' => false,
                'sms' => false,
                'telegram_chat_id' => null,
                'phone_number' => null,
            ]
        );

        return response()->json([
            'settings' => [
                'email' => (bool)$settings->email,
                'push' => (bool)$settings->push,
                'telegram' => (bool)$settings->telegram,
                'sms' => (bool)$settings->sms,
            ],
            'telegram_chat_id' => $settings->telegram_chat_id,
            'phone_number' => $settings->phone_number,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'settings' => 'required|array',
            'settings.email' => 'required|boolean',
            'settings.push' => 'required|boolean',
            'settings.telegram' => 'required|boolean',
            'settings.sms' => 'required|boolean',
            'telegram_chat_id' => 'nullable|string|max:255',
            'phone_number' => 'nullable|string|max:20',
        ]);

        $user = Auth::user();
        $settings = UserNotificationSetting::updateOrCreate(
            ['user_id' => $user->id],
            [
                'email' => $validated['settings']['email'],
                'push' => $validated['settings']['push'],
                'telegram' => $validated['settings']['telegram'],
                'sms' => $validated['settings']['sms'],
                'telegram_chat_id' => $validated['telegram_chat_id'] ?? null,
                'phone_number' => $validated['phone_number'] ?? null,
            ]
        );

        return response()->json([
            'message' => 'Notification settings updated successfully',
            'settings' => $settings,
        ]);
    }
}