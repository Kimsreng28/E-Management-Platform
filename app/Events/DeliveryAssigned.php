<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\Delivery;

class DeliveryAssigned implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $delivery;

    public function __construct(Delivery $delivery)
    {
        $this->delivery = $delivery;
    }

    public function broadcastOn()
    {
        // Notify customer about the delivery assignment
        return [
            new PrivateChannel('user.' . $this->delivery->order->user_id),
            new PrivateChannel('user.' . $this->delivery->delivery_agent_id),
        ];
    }

    public function broadcastAs()
    {
        return 'delivery.assigned';
    }

    public function broadcastWith()
    {
        return [
            'delivery' => [
                'id' => $this->delivery->id,
                'tracking_number' => $this->delivery->tracking_number,
                'status' => $this->delivery->status,
                'estimated_arrival_time' => $this->delivery->estimated_arrival_time,
                'agent' => $this->delivery->agent,
                'order' => $this->delivery->order
            ],
            'message' => 'Your delivery has been assigned to an agent'
        ];
    }
}
