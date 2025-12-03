<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserPresenceUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $userId;
    public $isOnline;
    public $timestamp;

    public function __construct($userId, $isOnline)
    {
        $this->userId = $userId;
        $this->isOnline = $isOnline;
        $this->timestamp = now()->toISOString();
    }

    public function broadcastOn()
    {
        // Get all conversations the user is in
        $user = \App\Models\User::find($this->userId);
        $conversationIds = $user->conversations()->pluck('conversations.id')->toArray();

        $channels = [
            new PrivateChannel('user.presence.' . $this->userId),
        ];

        // Also broadcast to all conversation channels the user is in
        foreach ($conversationIds as $conversationId) {
            $channels[] = new PresenceChannel('chat.' . $conversationId);
        }

        return $channels;
    }

    public function broadcastAs()
    {
        return 'user.presence';
    }

    public function broadcastWith()
    {
        return [
            'user_id' => $this->userId,
            'is_online' => $this->isOnline,
            'last_seen' => $this->timestamp,
        ];
    }
}
