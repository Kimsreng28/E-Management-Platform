<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use App\Models\Product;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class SearchController extends Controller
{
    // Cache durations in seconds
    private $cacheDurations = [
        'search_results' => 300, // 5 minutes
        'product_search' => 600, // 10 minutes
        'quick_search' => 180, // 3 minutes for quick searches
    ];

    // Clear search-related caches
    private function clearSearchCaches($query = null)
    {
        if ($query) {
            $searchKey = md5(strtolower(trim($query)));
            Cache::forget("search:{$searchKey}");
            Cache::forget("search:products:{$searchKey}");
        }

        // Clear all search caches
        $keys = Cache::getRedis()->keys('*search*');
        foreach ($keys as $key) {
            Cache::forget(str_replace('laravel_database_', '', $key));
        }
    }

    public function search(Request $request)
    {
        try {
            $query = $request->get('q', '');
            $type = $request->get('type', 'all');

            if (empty($query)) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'products' => [],
                        'orders' => [],
                        'customers' => []
                    ]
                ]);
            }

            // Create cache key based on search parameters
            $cacheKey = 'search:' . md5(serialize([
                'query' => $query,
                'type' => $type,
                'version' => 'v2' // cache version for breaking changes
            ]));

            $results = Cache::remember($cacheKey, $this->cacheDurations['search_results'], function () use ($query, $type) {
                $results = [
                    'products' => [],
                    'orders' => [],
                    'customers' => []
                ];

                // Search products
                if ($type === 'all' || $type === 'products') {
                    $results['products'] = Product::where('is_active', true)
                    ->whereNull('deleted_at')
                    ->where(function ($q) use ($query) {
                        $q->where('name', 'like', "%{$query}%")
                        ->orWhere('model_code', 'like', "%{$query}%")
                        ->orWhere('short_description', 'like', "%{$query}%")
                        ->orWhere('description', 'like', "%{$query}%");
                    })
                    ->with(['images' => function($q) {
                        $q->where('is_primary', true)->orWhere('order', 0);
                    }, 'category', 'brand'])
                    ->take(5)
                    ->get()
                    ->map(function ($product) {
                        return [
                            'id' => $product->id,
                            'name' => $product->name,
                            'slug' => $product->slug,
                            'sku' => $product->model_code,
                            'price' => $product->price,
                            'discount_price' => $product->discount_price ?? $product->price,
                            'stock' => $product->stock,
                            'image' => optional($product->images->first())->path,
                            'type' => 'product',
                            'category' => $product->category->name ?? null,
                            'brand' => $product->brand->name ?? null,
                        ];
                    });
                }

                // Search orders
                if ($type === 'all' || $type === 'orders') {
                    $results['orders'] = Order::where(function ($q) use ($query) {
                        $q->where('order_number', 'like', "%{$query}%")
                        ->orWhere('status', 'like', "%{$query}%")
                        ->orWhereHas('user', function ($q2) use ($query) {
                            $q2->where('name', 'like', "%{$query}%")
                                ->orWhere('email', 'like', "%{$query}%");
                        });
                    })
                    ->with(['user:id,name,email'])
                    ->take(5)
                    ->get()
                    ->map(function ($order) {
                        return [
                            'id' => $order->id,
                            'order_number' => $order->order_number,
                            'status' => $order->status,
                            'total' => $order->total,
                            'customer_name' => $order->user->name,
                            'created_at' => $order->created_at->format('M d, Y H:i'),
                            'type' => 'order'
                        ];
                    });
                }

                // Search customers
                if ($type === 'all' || $type === 'customers') {
                    $results['customers'] = User::where('role_id', 2) // 2 = customer
                    ->where(function ($q) use ($query) {
                        $q->where('name', 'like', "%{$query}%")
                        ->orWhere('email', 'like', "%{$query}%")
                        ->orWhere('phone', 'like', "%{$query}%");
                    })
                    ->withCount('orders')
                    ->take(5)
                    ->get()
                    ->map(function ($user) {
                        return [
                            'id' => $user->id,
                            'name' => $user->name,
                            'email' => $user->email,
                            'phone' => $user->phone,
                            'orders_count' => $user->orders_count,
                            'is_active' => $user->is_active,
                            'type' => 'customer'
                        ];
                    });
                }

                return $results;
            });

            // Add cache metadata to response
            $response = [
                'success' => true,
                'data' => $results,
                'meta' => [
                    'query' => $query,
                    'type' => $type,
                    'cached' => true,
                    'results_count' => [
                        'products' => count($results['products']),
                        'orders' => count($results['orders']),
                        'customers' => count($results['customers'])
                    ]
                ]
            ];

            return response()->json($response);

        } catch (\Exception $e) {
            Log::error('Search error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Search failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function searchForProduct(Request $request)
    {
        try {
            $query = $request->get('q', '');
            $type = $request->get('type', 'all');

            if (empty($query)) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'products' => [],
                    ]
                ]);
            }

            // Create cache key for product search
            $cacheKey = 'search:products:' . md5(serialize([
                'query' => $query,
                'type' => $type,
                'version' => 'v2'
            ]));

            $results = Cache::remember($cacheKey, $this->cacheDurations['product_search'], function () use ($query, $type) {
                $results = [
                    'products' => [],
                ];

                // Search products
                if ($type === 'all' || $type === 'products') {
                    $results['products'] = Product::where('is_active', true)
                    ->whereNull('deleted_at')
                    ->where(function ($q) use ($query) {
                        $q->where('name', 'like', "%{$query}%")
                        ->orWhere('model_code', 'like', "%{$query}%")
                        ->orWhere('short_description', 'like', "%{$query}%")
                        ->orWhere('description', 'like', "%{$query}%")
                        ->orWhere('barcode', 'like', "%{$query}%");
                    })
                    ->with(['images' => function($q) {
                        $q->where('is_primary', true)->orWhere('order', 0);
                    }, 'category', 'brand'])
                    ->take(10) // Increased limit for product-only search
                    ->get()
                    ->map(function ($product) {
                        return [
                            'id' => $product->id,
                            'name' => $product->name,
                            "slug" => $product->slug,
                            'sku' => $product->model_code,
                            'price' => $product->price,
                            'discount_price' => $product->discount_price ?? $product->price,
                            'stock' => $product->stock,
                            'stock_status' => $product->stock_status,
                            'image' => optional($product->images->first())->path,
                            'type' => 'product',
                            'category' => $product->category->name ?? null,
                            'brand' => $product->brand->name ?? null,
                            'is_featured' => $product->is_featured,
                            'rating' => $product->reviews()->avg('rating') ?? 0,
                        ];
                    });
                }

                return $results;
            });

            $response = [
                'success' => true,
                'data' => $results,
                'meta' => [
                    'query' => $query,
                    'type' => $type,
                    'cached' => true,
                    'total_products' => count($results['products'])
                ]
            ];

            return response()->json($response);

        } catch (\Exception $e) {
            Log::error('Product search error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Product search failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    // Advanced search with filters
    public function advancedSearch(Request $request)
    {
        try {
            $query = $request->get('q', '');
            $category = $request->get('category');
            $brand = $request->get('brand');
            $minPrice = $request->get('min_price');
            $maxPrice = $request->get('max_price');
            $inStock = $request->get('in_stock');
            $sortBy = $request->get('sort_by', 'name');
            $sortOrder = $request->get('sort_order', 'asc');
            $perPage = $request->get('per_page', 15);

            $cacheKey = 'search:advanced:' . md5(serialize($request->all()));

            $results = Cache::remember($cacheKey, $this->cacheDurations['search_results'], function () use (
                $query, $category, $brand, $minPrice, $maxPrice, $inStock, $sortBy, $sortOrder, $perPage
            ) {
                $productQuery = Product::where('is_active', true)
                    ->whereNull('deleted_at');

                // Search query
                if (!empty($query)) {
                    $productQuery->where(function ($q) use ($query) {
                        $q->where('name', 'like', "%{$query}%")
                          ->orWhere('model_code', 'like', "%{$query}%")
                          ->orWhere('short_description', 'like', "%{$query}%")
                          ->orWhere('description', 'like', "%{$query}%")
                          ->orWhere('barcode', 'like', "%{$query}%");
                    });
                }

                // Category filter
                if (!empty($category)) {
                    $productQuery->where('category_id', $category);
                }

                // Brand filter
                if (!empty($brand)) {
                    $productQuery->where('brand_id', $brand);
                }

                // Price range filter
                if (!empty($minPrice)) {
                    $productQuery->where('price', '>=', $minPrice);
                }

                if (!empty($maxPrice)) {
                    $productQuery->where('price', '<=', $maxPrice);
                }

                // Stock filter
                if ($inStock === 'true') {
                    $productQuery->where('stock', '>', 0);
                } elseif ($inStock === 'false') {
                    $productQuery->where('stock', '<=', 0);
                }

                // Sorting
                $allowedSorts = ['name', 'price', 'stock', 'created_at', 'updated_at'];
                if (in_array($sortBy, $allowedSorts)) {
                    $productQuery->orderBy($sortBy, $sortOrder);
                }

                // Include relationships
                $productQuery->with(['images' => function($q) {
                    $q->where('is_primary', true);
                }, 'category', 'brand']);

                return $productQuery->paginate($perPage);
            });

            return response()->json([
                'success' => true,
                'data' => $results->items(),
                'meta' => [
                    'current_page' => $results->currentPage(),
                    'last_page' => $results->lastPage(),
                    'per_page' => $results->perPage(),
                    'total' => $results->total(),
                    'query' => $query,
                    'filters' => [
                        'category' => $category,
                        'brand' => $brand,
                        'price_range' => [$minPrice, $maxPrice],
                        'in_stock' => $inStock
                    ],
                    'cached' => true
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Advanced search error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Advanced search failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    // Quick search for autocomplete
    public function quickSearch(Request $request)
    {
        try {
            $query = $request->get('q', '');

            if (empty($query) || strlen($query) < 2) {
                return response()->json([
                    'success' => true,
                    'data' => []
                ]);
            }

            $cacheKey = 'search:quick:' . md5($query);

            $results = Cache::remember($cacheKey, $this->cacheDurations['quick_search'], function () use ($query) {
                return Product::where('is_active', true)
                    ->whereNull('deleted_at')
                    ->where(function ($q) use ($query) {
                        $q->where('name', 'like', "%{$query}%")
                          ->orWhere('model_code', 'like', "%{$query}%");
                    })
                    ->with(['images' => function($q) {
                        $q->where('is_primary', true);
                    }])
                    ->take(8)
                    ->get()
                    ->map(function ($product) {
                        return [
                            'id' => $product->id,
                            'name' => $product->name,
                            'slug' => $product->slug,
                            'price' => $product->price,
                            'discount_price' => $product->discount_price ?? $product->price,
                            'image' => optional($product->images->first())->path,
                            'type' => 'product'
                        ];
                    });
            });

            return response()->json([
                'success' => true,
                'data' => $results,
                'meta' => [
                    'query' => $query,
                    'cached' => true,
                    'count' => count($results)
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Quick search error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Quick search failed'
            ], 500);
        }
    }

    // Clear search cache (admin utility)
    public function clearSearchCache(Request $request)
    {
        try {
            $query = $request->get('query');
            $this->clearSearchCaches($query);

            return response()->json([
                'success' => true,
                'message' => $query ? "Search cache cleared for '{$query}'" : 'All search caches cleared'
            ]);

        } catch (\Exception $e) {
            Log::error('Clear search cache error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear search cache'
            ], 500);
        }
    }

    // Get search statistics
    public function searchStats()
    {
        $cacheKey = 'search:stats';

        $stats = Cache::remember($cacheKey, 3600, function () { // 1 hour cache
            // Get popular search terms (you might want to log searches for this)
            $popularSearches = [];

            // Get search performance stats
            $totalProducts = Product::where('is_active', true)->count();
            $productsWithImages = Product::where('is_active', true)
                ->whereHas('images')
                ->count();

            return [
                'total_searchable_products' => $totalProducts,
                'products_with_images' => $productsWithImages,
                'popular_searches' => $popularSearches,
                'cache_status' => [
                    'total_cached_searches' => count(Cache::getRedis()->keys('*search*')),
                    'cache_driver' => config('cache.default')
                ]
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    // Cache debugging methods
    public function debugSearchCache($key)
    {
        $exists = Cache::has($key);
        $data = Cache::get($key);
        $ttl = Cache::getRedis()->ttl(Cache::getPrefix() . $key);

        return [
            'key' => $key,
            'exists' => $exists,
            'ttl_seconds' => $ttl,
            'data_sample' => $exists ? (is_array($data) ? array_slice($data, 0, 2) : $data) : null
        ];
    }

    public function getSearchCacheKeys()
    {
        $keys = Cache::getRedis()->keys('*search*');

        $cacheInfo = [];
        foreach ($keys as $key) {
            $cleanKey = str_replace('laravel_database_', '', $key);
            $ttl = Cache::getRedis()->ttl($key);
            $size = strlen(serialize(Cache::get($cleanKey)));
            $cacheInfo[] = [
                'key' => $cleanKey,
                'ttl' => $ttl,
                'size_bytes' => $size,
                'size_human' => $this->formatBytes($size)
            ];
        }

        return $cacheInfo;
    }

    private function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}