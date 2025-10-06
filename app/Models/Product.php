<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

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
        'barcode',
    ];

    protected $casts = [
        'is_featured' => 'boolean',
        'is_active' => 'boolean',
        'specifications' => 'array',
    ];

    protected $appends = ['discounted_price', 'is_new'];

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

    public function orders()
{
    return $this->belongsToMany(Order::class, 'order_items')
        ->withPivot(['quantity', 'unit_price', 'total_price']);
}

    public function wishlists()
    {
        return $this->hasMany(Wishlist::class);
    }

    // Product reviews
    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function totalRatings()
    {
        return $this->reviews()->count();
    }

    // Product creator
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Product main image
    public function getMainImageAttribute()
    {
        return $this->images->where('is_primary', true)->first() ?? $this->images->first();
    }

    // Product average rating
    public function getAverageRatingAttribute()
    {
        $avg = $this->reviews()->avg('rating') ?? 0;
        return round((float) $avg, 1);
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

    // Get discounted price
    public function getDiscountedPriceAttribute()
    {
        if ($this->discount > 0) {
            return round($this->price * (1 - $this->discount / 100), 2);
        }
        return $this->price;
    }

    // Check if product is new
    public function getIsNewAttribute()
    {
        return $this->created_at >= now()->subDays($this->newProductDays);
    }

    // Get popular products (based on sales/views)
    public function scopePopular($query, $limit = 10)
    {
        return $query->withCount('orderItems')
            ->orderBy('order_items_count', 'desc')
            ->limit($limit);
    }

    // Get recommended products (based on category and sales)
    public function scopeRecommended($query, $categoryId = null, $limit = 10)
    {
        $query = $query->withCount('orderItems')
            ->where('is_active', true);

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        return $query->orderBy('order_items_count', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit($limit);
    }

    // Get highly rated products
    public function scopeHighlyRated($query, $minRating = 4, $limit = 10)
    {
        return $query->select('products.*')
            ->join('reviews', 'reviews.product_id', '=', 'products.id')
            ->whereNull('reviews.deleted_at') // still ignore soft-deleted reviews
            ->groupBy('products.id') // required for PostgreSQL
            ->havingRaw('AVG(reviews.rating) >= ?', [$minRating])
            ->orderByRaw('AVG(reviews.rating) DESC')
            ->orderByRaw('COUNT(reviews.id) DESC')
            ->limit($limit);
    }

    // Get products reviewed by a specific user
    public function scopeReviewedByUser($query, $userId)
    {
        return $query->whereHas('reviews', function ($q) use ($userId) {
            $q->where('user_id', $userId)
            ->where('is_approved', true);
        });
    }

        protected static function boot()
    {
        parent::boot();

        // Generate barcode when creating a new product
        static::creating(function ($product) {
            if (empty($product->barcode)) {
                $product->barcode = self::generateUniqueBarcode();
            }
        });
    }

    public static function generateUniqueBarcode()
    {
        do {
            // Generate a 12-digit barcode (EAN-13 without check digit)
            $barcode = rand(100000000000, 999999999999);
        } while (self::where('barcode', $barcode)->exists());

        return $barcode;
    }

    public function getBarcodeImageAttribute()
    {
        if (!$this->barcode) {
            return null;
        }

        return QrCode::size(100)
            ->format('svg')
            ->generate($this->barcode);
    }
}