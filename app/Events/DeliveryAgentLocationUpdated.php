<?php

namespace App\Events;

use App\Models\Delivery;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeliveryAgentLocationUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public $delivery_id;
    public $lat;
    public $lng;
    public $user_id;
    public $timestamp;

    /**
     * Create a new event instance.
     */
    public function __construct(Delivery $delivery, $lat, $lng)
    {
        $this->delivery_id = $delivery->id;
        $this->lat = $lat;
        $this->lng = $lng;
        $this->user_id = $delivery->delivery_agent_id;
        $this->timestamp = now()->toISOString();
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        // Broadcast to both the delivery channel AND the agent's private channel
        return [
            new PrivateChannel('delivery.' . $this->delivery_id),
            new PrivateChannel('user.' . $this->user_id),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'delivery.location.updated';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'delivery_id' => $this->delivery_id,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'timestamp' => $this->timestamp,
            'agent_id' => $this->user_id
        ];
    }
}
