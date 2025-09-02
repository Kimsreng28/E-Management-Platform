<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controller;

class ConversationController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $conversations = $user->conversations()
            ->with(['participants', 'latestMessage'])
            ->orderByDesc(
                Message::select('created_at')
                    ->whereColumn('conversation_id', 'conversations.id')
                    ->latest()
                    ->limit(1)
            )
            ->get();

        return response()->json([
            'conversations' => $conversations
        ]);
    }

    public function show(Conversation $conversation)
    {
        $this->authorize('view', $conversation);

        $messages = $conversation->messages()
            ->with('user')
            ->orderBy('created_at', 'asc')
            ->get();

        // Mark messages as read
        $conversation->markAsRead(Auth::id());

        return response()->json([
            'conversation' => $conversation,
            'messages' => $messages
        ]);
    }

    public function findOrCreateConversation(Request $request, $userId)
    {
        $otherUser = User::findOrFail($userId);
        $currentUser = Auth::user();

        // Check if a conversation already exists between these users
        $conversation = Conversation::whereHas('participants', function ($query) use ($currentUser) {
            $query->where('user_id', $currentUser->id);
        })->whereHas('participants', function ($query) use ($otherUser) {
            $query->where('user_id', $otherUser->id);
        })->first();

        if (!$conversation) {
            // Create a new conversation
            $conversation = Conversation::create([
                'title' => "Chat between {$currentUser->name} and {$otherUser->name}",
                'type' => $otherUser->isDelivery() ? 'customer_to_delivery' : 'customer_to_customer'
            ]);

            // Attach participants
            $conversation->participants()->attach([$currentUser->id, $otherUser->id]);
        }

        return response()->json([
            'conversation' => $conversation
        ]);
    }

    public function getDeliveryAgents()
    {
        $deliveryAgents = User::where('role_id', 3) // Assuming delivery role ID is 3
            ->where('is_active', true)
            ->get();

        return response()->json([
            'delivery_agents' => $deliveryAgents
        ]);
    }
}