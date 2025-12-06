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

class DeliveryStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $delivery;
    public $status;

    public function __construct(Delivery $delivery, $status)
    {
        $this->delivery = $delivery;
        $this->status = $status;
    }

    public function broadcastAs()
    {
        return 'delivery.status.updated';
    }

    public function broadcastOn()
    {
        // Notify customer, delivery agent
        return [
            new PrivateChannel('delivery.' . $this->delivery->id),
            new PrivateChannel('user.' . $this->delivery->order->user_id),
            new PrivateChannel('user.' . $this->delivery->delivery_agent_id)
        ];
    }

    public function broadcastWith()
    {
        return [
            'delivery' => [
                'id' => $this->delivery->id,
                'status' => $this->status,
                'estimated_arrival_time' => $this->delivery->estimated_arrival_time,
                'picked_up_at' => $this->delivery->picked_up_at,
                'out_for_delivery_at' => $this->delivery->out_for_delivery_at,
                'delivered_at' => $this->delivery->delivered_at,
                'received_at' => $this->delivery->received_at,
                'agent_lat' => $this->delivery->agent_lat,
                'agent_lng' => $this->delivery->agent_lng,
                'delivery_options' => $this->delivery->delivery_options,
                'customer_accepted_at' => $this->delivery->customer_accepted_at,
                'agent' => $this->delivery->agent,
                'trackingHistory' => $this->delivery->trackingHistory
            ],
            'message' => 'Delivery status updated to ' . $this->status
        ];
    }
}