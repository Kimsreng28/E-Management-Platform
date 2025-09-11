<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallRejected implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $callData;
    public $conversation;
    public $message;

    public function __construct($callData, $conversation, $message = null)
    {
        $this->callData = $callData;
        $this->conversation = $conversation;
        $this->message = $message;
    }

    public function broadcastOn()
    {
        return new PresenceChannel('chat.' . $this->conversation->id);
    }

    public function broadcastAs()
    {
        return 'call.rejected';
    }

    public function broadcastWith()
    {
        return [
            'callData' => $this->callData,
            'message' => $this->message ? [
                'id' => $this->message->id,
                'type' => 'call',
                'call_type' => $this->message->call_type,
                'call_status' => $this->message->call_status,
                'call_reason' => $this->message->call_reason,
                'duration' => $this->message->duration,
                'user_id' => $this->message->user_id,
                'user' => [
                    'id' => $this->message->user->id,
                    'name' => $this->message->user->name,
                    'avatar' => $this->message->user->avatar,
                ],
                'created_at' => $this->message->created_at,
            ] : null,
        ];
    }
}