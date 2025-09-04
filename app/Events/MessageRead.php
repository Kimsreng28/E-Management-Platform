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

class MessageRead implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $conversationId;
    public $userId;

    public function __construct($conversationId, $userId)
    {

        $this->conversationId = $conversationId;
        $this->userId = $userId;
    }

    public function broadcastAs()
    {
        return 'message.read';
    }

    public function broadcastOn()
    {
        return new PresenceChannel('chat.' . $this->conversationId);
    }

    public function broadcastWith()
    {
        return [
            'conversation_id' => $this->conversationId,
            'user_id' => $this->userId,
            'read_at' => now()
        ];
    }
}
