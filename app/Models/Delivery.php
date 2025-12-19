<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Delivery extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'delivery_agent_id',
        'status',
        'assigned_at',
        'picked_up_at',
        'out_for_delivery_at',
        'delivered_at',
        'received_at',
        'delivery_notes',
        'tracking_number',
        'delivery_fee',
        'distance',
        'agent_lat',
        'agent_lng',
        'estimated_arrival_time',
        'customer_accepted_at',
        'delivery_options',
        'agent_rating',
        'agent_rating_comment',
        'agent_rated_at'
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'out_for_delivery_at' => 'datetime',
        'delivered_at' => 'datetime',
        'received_at' => 'datetime',
        'customer_accepted_at' => 'datetime',
        'estimated_arrival_time' => 'datetime',
        'delivery_fee' => 'decimal:2',
        'distance' => 'decimal:2',
        'delivery_options' => 'array',
        'received_at' => 'datetime',

        'agent_rating' => 'decimal:2',
        'agent_rated_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'delivery_agent_id');
    }

    public function trackingHistory()
    {
        return $this->hasMany(DeliveryTracking::class);
    }

    public function customer()
    {
        return $this->hasOneThrough(User::class, Order::class, 'id', 'id', 'order_id', 'user_id');
    }

     public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }
}
