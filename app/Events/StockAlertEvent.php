<?php

namespace App\Events;

use App\Models\Product;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StockAlertEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $product;
    public $admins;

    public function __construct(Product $product, $admins)
    {
        $this->product = $product;
        $this->admins = $admins;
    }

    public function broadcastOn()
    {
        return $this->admins->map(fn($admin) => new PrivateChannel('admin.' . $admin->id))->toArray();
    }

    public function broadcastWith()
    {
        return [
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'stock' => $this->product->stock,
            'low_stock_threshold' => $this->product->low_stock_threshold,
            'alert_type' => $this->product->stock <= 0 ? 'out_of_stock' : 'low_stock',
        ];
    }
}