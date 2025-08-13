<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Models\TelegramNotification;
use Illuminate\Routing\Controller as RoutingController;
use Illuminate\Support\Facades\Http;

class TelegramNotificationController extends RoutingController
{
    // Create a Telegram notification record in DB
    public function store(Request $request)
    {
        $request->validate([
            'order_id' => 'required|exists:orders,id',
            'chat_id' => 'required|string',
            'message' => 'required|string'
        ]);

        $notification = TelegramNotification::create([
            'order_id' => $request->order_id,
            'chat_id' => $request->chat_id,
            'message' => $request->message
        ]);

        return response()->json([
            'message' => 'Telegram notification created',
            'data' => $notification
        ]);
    }

    // Send message to Telegram API
    public function send($id)
    {
        $notification = TelegramNotification::findOrFail($id);

        if ($notification->is_sent) {
            return response()->json(['message' => 'Already sent'], 400);
        }

        $botToken = env('TELEGRAM_TOKEN');
        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";

        $response = Http::post($url, [
            'chat_id' => $notification->chat_id,
            'text' => $notification->message
        ]);

        if ($response->successful()) {
            $notification->update([
                'is_sent' => true,
                'sent_at' => now()
            ]);

            return response()->json(['message' => 'Message sent successfully']);
        } else {
            return response()->json(['message' => 'Failed to send message'], 500);
        }
    }

    // View Telegram notifications
    public function index()
    {
        return TelegramNotification::orderBy('created_at', 'desc')->get();
    }
}
