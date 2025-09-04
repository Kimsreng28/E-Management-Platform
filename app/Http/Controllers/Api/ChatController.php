<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageSent;
use App\Events\MessageRead;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class ChatController extends Controller
{

    use AuthorizesRequests;

    public function sendMessage(Request $request, Conversation $conversation)
    {
        try {
            $this->authorize('view', $conversation);

            $request->validate([
                'body' => 'required|string',
                'type' => 'sometimes|in:text,image,file'
            ]);

            $message = $conversation->messages()->create([
                'user_id' => Auth::id(),
                'body' => $request->body,
                'type' => $request->type ?? 'text'
            ]);

            // Load user relationship for the event
            $message->load('user');

            // Broadcast the message
            broadcast(new MessageSent($message, $conversation));

            return response()->json([
                'message' => $message,
                'status' => 'Message sent successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error sending message: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to send message',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function markAsRead(Conversation $conversation)
    {
        try {
            $this->authorize('view', $conversation);

            $conversation->markAsRead(Auth::id());

            // Broadcast that messages were read
            broadcast(new MessageRead($conversation->id, Auth::id()));

            return response()->json([
                'status' => 'Messages marked as read'
            ]);
        } catch (\Exception $e) {
            Log::error('Error marking as read: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to mark messages as read',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}