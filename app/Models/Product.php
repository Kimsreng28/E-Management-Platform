<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'model_code',
        'slug',
        'stock',
        'price',
        'cost_price',
        'short_description',
        'description',
        'category_id',
        'brand_id',
        'warranty_months',
        'is_featured',
        'is_active',
        'specifications',
        'created_by',
        'low_stock_threshold',
        'stock_status',
        'discount',
    ];

    protected $casts = [
        'is_featured' => 'boolean',
        'is_active' => 'boolean',
        'specifications' => 'array'
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class);
    }

    public function videos()
    {
        return $this->hasMany(ProductVideo::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getMainImageAttribute()
    {
        return $this->images->where('is_primary', true)->first() ?? $this->images->first();
    }

    public function getAverageRatingAttribute()
    {
        return $this->reviews()->avg('rating') ?? 0;
    }

    // Auto calculate stock status
    public function getStockStatusAttribute($value)
    {
        $threshold = $this->low_stock_threshold;

        // fallback to business setting if product threshold is null
        if ($threshold === null) {
            $businessSettings = BusinessSetting::where('user_id', $this->created_by)->first();
            $threshold = $businessSettings->low_stock_threshold ?? 10;
        }

        if ($this->stock <= 0) {
            return 'Out of Stock';
        } elseif ($this->stock <= $threshold) {
            return 'Inactive';
        }

        return 'Active';
    }

    // Check if product is in low stock
    public function getIsLowStockAttribute()
    {
        $threshold = $this->low_stock_threshold;
        if ($threshold === null) {
            $businessSettings = BusinessSetting::where('user_id', $this->created_by)->first();
            $threshold = $businessSettings->low_stock_threshold ?? 10;
        }

        return $this->stock > 0 && $this->stock <= $threshold;
    }

    public function getDiscountedPriceAttribute()
    {
        if ($this->discount > 0) {
            return round($this->price * (1 - $this->discount / 100), 2);
        }
        return $this->price;
    }
}