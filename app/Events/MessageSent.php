<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\Message;
use App\Models\Conversation;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\Log;

class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;
    public $conversation;

    public function __construct(Message $message, Conversation $conversation)
    {
        Log::info('MessageSent event fired', [
            'message_id' => $message->id,
            'conversation_id' => $conversation->id,
            'users_in_conversation' => $conversation->participants->pluck('id')
        ]);
        $this->message = $message;
        $this->conversation = $conversation;
    }

    public function broadcastOn()
    {
        return new PresenceChannel('chat.' . $this->conversation->id);
    }

    public function broadcastAs()
    {
        return 'message.sent';
    }

    public function broadcastWith()
    {
        return [
            'message' => [
                'id' => $this->message->id,
                'body' => $this->message->body,
                'type' => $this->message->type,
                'user_id' => $this->message->user_id,
                'user' => [
                    'id' => $this->message->user->id,
                    'name' => $this->message->user->name,
                    'avatar' => $this->message->user->avatar,
                ],
                'conversation_id' => $this->message->conversation_id,
                'created_at' => $this->message->created_at,
                'read_at' => $this->message->read_at,
            ]
        ];
    }
}