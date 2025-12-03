<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UnreadCountUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $conversationId;
    public $unreadCount;
    public $userId;

    public function __construct($conversationId, $unreadCount, $userId)
    {
        $this->conversationId = $conversationId;
        $this->unreadCount = $unreadCount;
        $this->userId = $userId;
    }

    public function broadcastOn()
    {
        return new PresenceChannel('chat.' . $this->conversationId);
    }

    public function broadcastAs()
    {
        return 'unread.updated';
    }

    public function broadcastWith()
    {
        return [
            'conversation_id' => $this->conversationId,
            'unread_count' => $this->unreadCount,
            'user_id' => $this->userId,
            'timestamp' => now()->toISOString()
        ];
    }
}
