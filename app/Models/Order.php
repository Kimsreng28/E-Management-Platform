<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'order_number',
        'user_id',
        'subtotal',
        'tax_amount',
        'shipping_cost',
        'discount_amount',
        'total',
        'status',
        'shipping_address_id',
        'billing_address_id',
        'notes'
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total' => 'decimal:2'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function shippingAddress()
    {
        return $this->belongsTo(Address::class, 'shipping_address_id');
    }

    public function billingAddress()
    {
        return $this->belongsTo(Address::class, 'billing_address_id');
    }

    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }

    public function notifications()
    {
        return $this->morphMany(Notification::class, 'notifiable');
    }

    public function telegramNotifications()
    {
        return $this->hasMany(TelegramNotification::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class, 'order_id');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($order) {
            $order->order_number = 'ORD-' . strtoupper(uniqid());
        });
    }

    public function delivery()
    {
        return $this->hasOne(Delivery::class);
    }

    // get total successfully order
    public function getSuccessfulOrdersCount()
    {
        return $this->products()
            ->join('order_items', 'products.id', '=', 'order_items.product_id')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereIn('orders.status', ['completed', 'delivered', 'shipped'])
            ->count();
    }

    // update order base on the successful order
    public function updateOrderFromOrders()
    {
        $successfulOrdersCount = $this->getSuccessfulOrdersCount();
        $this->update(['order' => $successfulOrdersCount]);
    }

    // scope to order by successful order
    public function scopeOrderByPopularity($query)
    {
        return $query->orderBy('order', 'desc');
    }
}
