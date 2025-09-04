<?php

namespace App\Http\Controllers\Api;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class ConversationController extends Controller
{

    use AuthorizesRequests;

    public function index()
    {
        $user = Auth::user();
        $conversations = $user->conversations()
            ->with(['participants', 'latestMessage'])
            ->withCount(['messages as unread_count' => function ($query) use ($user) {
                $query->where('user_id', '!=', $user->id)
                    ->whereNull('read_at');
            }])
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
            // Determine conversation type based on user roles
            $type = 'customer_to_customer';
            if ($currentUser->role->name === 'customer' && $otherUser->role->name === 'delivery') {
                $type = 'customer_to_delivery';
            } elseif ($currentUser->role->name === 'delivery' && $otherUser->role->name === 'customer') {
                $type = 'delivery_to_customer';
            }

            // Create a new conversation
            $conversation = Conversation::create([
                'title' => "Chat between {$currentUser->name} and {$otherUser->name}",
                'type' => $type
            ]);

            // Attach participants
            $conversation->participants()->attach([$currentUser->id, $otherUser->id]);
        }

        return response()->json([
            'conversation' => $conversation->load('participants')
        ]);
    }

    public function getDeliveryAgents()
    {
        $deliveryAgents = User::where('role_id', 3)
            ->where('is_active', true)
            ->get();

        return response()->json([
            'delivery_agents' => $deliveryAgents
        ]);
    }

    // Get customers (role_id = 2)
    public function getCustomers()
    {
        $customers = User::where('role_id', 2)
            ->where('is_active', true)
            ->get();

        return response()->json([
            'customers' => $customers
        ]);
    }
}