<?php

namespace App\Http\Controllers;

use App\Events\MessageSent;
use App\Events\MessageRead;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controller;

class ChatController extends Controller
{
    public function sendMessage(Request $request, Conversation $conversation)
    {
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
        broadcast(new MessageSent($message, $conversation))->toOthers();

        return response()->json([
            'message' => $message,
            'status' => 'Message sent successfully'
        ]);
    }

    public function markAsRead(Conversation $conversation)
    {
        $this->authorize('view', $conversation);

        $conversation->markAsRead(Auth::id());

        // Broadcast that messages were read
        broadcast(new MessageRead($conversation->id, Auth::id()));

        return response()->json([
            'status' => 'Messages marked as read'
        ]);
    }
}