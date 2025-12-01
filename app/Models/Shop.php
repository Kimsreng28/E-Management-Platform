<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shop extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'vendor_id', 'name', 'slug', 'description', 'logo', 'banner',
        'phone', 'email', 'address', 'social_links', 'rating',
        'total_ratings', 'is_verified', 'is_active'
    ];

    protected $casts = [
        'social_links' => 'array',
        'rating' => 'decimal:2',
        'is_verified' => 'boolean',
        'is_active' => 'boolean'
    ];

    public function vendor()
    {
        return $this->belongsTo(User::class, 'vendor_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function ratings()
    {
        return $this->hasMany(ShopRating::class);
    }

    public function getAverageRatingAttribute()
    {
        return $this->ratings()->avg('rating') ?? 0;
    }

    public function getTotalRatingsAttribute()
    {
        return $this->ratings()->count();
    }
}
