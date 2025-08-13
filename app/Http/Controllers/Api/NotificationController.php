<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use App\Models\Notification;

class NotificationController extends Controller
{
    // List all notifications for the logged-in user
    public function index()
    {
        $user = Auth::user();

        $notifications = Notification::where('notifiable_id', $user->id)
            ->where('notifiable_type', get_class($user))
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($notifications);
    }

    // Mark a notification as read
    public function markAsRead($id)
    {
        $notification = Notification::findOrFail($id);
        $notification->update(['read_at' => now()]);

        return response()->json(['message' => 'Notification marked as read']);
    }

    // Create a new notification
    public function store(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'type' => 'required|string',
            'data' => 'required|array',
        ]);

        $notification = Notification::create([
            'type' => $request->type,
            'data' => $request->data,
            'notifiable_id' => $user->id,
            'notifiable_type' => get_class($user),
        ]);

        return response()->json($notification, 201);
    }
}
