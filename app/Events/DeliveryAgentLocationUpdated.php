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

class DeliveryAgentLocationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $delivery;
    public $lat;
    public $lng;

    public function __construct(Delivery $delivery, $lat, $lng)
    {
        $this->delivery = $delivery;
        $this->lat = $lat;
        $this->lng = $lng;
    }

    public function broadcastOn()
    {
        // Notify customer and admin for real-time tracking
        return [
            new PrivateChannel('delivery.location.' . $this->delivery->id),
            new PrivateChannel('user.' . $this->delivery->order->user_id),
            new Channel('admin.deliveries')
        ];
    }
}
