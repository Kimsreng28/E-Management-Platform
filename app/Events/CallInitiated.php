<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallInitiated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $callData;
    public $conversation;

    public function __construct($callData, $conversation)
    {
        $this->callData = $callData;
        $this->conversation = $conversation;
    }

    public function broadcastOn()
    {
        return new PresenceChannel('chat.' . $this->conversation->id);
    }

    public function broadcastAs()
    {
        return 'call.initiated';
    }
}