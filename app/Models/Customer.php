<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;


class Customer extends User
{
    use HasFactory;

    protected $table = 'users';

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('customer', function ($builder) {
            // Assuming customer role has ID 2 (adjust as needed)
            $builder->where('role_id', 2);
        });
    }

    // Address
    public function addresses()
    {
        return $this->hasMany(Address::class, 'user_id');
    }

    // Cart
    public function carts()
    {
        return $this->hasMany(Cart::class, 'user_id');
    }

    // Order
    public function orders()
    {
        return $this->hasMany(Order::class, 'user_id');
    }

    // reviews
    public function reviews()
    {
        return $this->hasMany(Review::class, 'user_id');
    }

    // profile
    public function profile()
    {
        return $this->hasOne(UserProfile::class, 'user_id');
    }

    // notification settings
    public function notificationSettings()
    {
        return $this->hasOne(UserNotificationSetting::class, 'user_id');
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function shop()
    {
        return $this->hasOne(Shop::class);
    }
}