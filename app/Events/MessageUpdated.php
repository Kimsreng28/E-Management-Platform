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

class MessageUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;

    public function __construct(Message $message)
    {
        $this->message = $message->load('user');
    }

    public function broadcastOn()
    {
        return new PresenceChannel('chat.' . $this->message->conversation_id);
    }

    public function broadcastAs()
    {
        return 'message.updated';
    }

    public function broadcastWith()
    {
        return [
            'message' => [
                'id' => $this->message->id,
                'body' => $this->message->body,
                'type' => $this->message->type,
                'user_id' => $this->message->user_id,
                'conversation_id' => $this->message->conversation_id,
                'created_at' => $this->message->created_at,
                'updated_at' => $this->message->updated_at,
                'user' => $this->message->user,
                'attachments' => $this->message->attachments
            ]
        ];
    }
}
