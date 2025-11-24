<?php

namespace App\Http\Controllers\Api;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    // Cache durations in seconds
    private $cacheDurations = [
        'category_stats' => 300, // 5 minutes
        'category_list' => 1800, // 30 minutes
        'category_detail' => 3600, // 1 hour
        'featured_categories' => 1800, // 30 minutes
        'category_products' => 1800, // 30 minutes
    ];

    // Clear category-related caches
    private function clearCategoryCaches($slug = null)
    {
        Cache::forget('category:stats');
        Cache::forget('categories:featured');
        Cache::forget('categories:list');

        if ($slug) {
            Cache::forget("category:{$slug}");
            Cache::forget("category:products:{$slug}");
        }

        // Clear all category listing caches
        $keys = Cache::getRedis()->keys('*categories:*');
        foreach ($keys as $key) {
            Cache::forget(str_replace('laravel_database_', '', $key));
        }

        // Also clear product caches since they might be affected
        $productKeys = Cache::getRedis()->keys('*products:*');
        foreach ($productKeys as $key) {
            Cache::forget(str_replace('laravel_database_', '', $key));
        }
    }

    // Get stats
    public function stats()
    {
        $cacheKey = 'category:stats';

        $data = Cache::remember($cacheKey, $this->cacheDurations['category_stats'], function () {
            $totalProducts = \App\Models\Product::count();
            $totalCategories = \App\Models\Category::count();
            $totalBrands = \App\Models\Brand::count();
            $support = "24/7";

            return [
                'totalProducts' => $totalProducts,
                'totalCategories' => $totalCategories,
                'totalBrands' => $totalBrands,
                'support' => $support,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    // Display a listing of categories.
    public function index(Request $request)
    {
        $cacheKey = 'categories:list:' . md5(serialize($request->all()));

        $result = Cache::remember($cacheKey, $this->cacheDurations['category_list'], function () use ($request) {
            $query = Category::query()->with('parent');

            // Search functionality
            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%")
                      ->orWhereHas('parent', function($q) use ($search) {
                          $q->where('name', 'like', "%{$search}%");
                      });
                });
            }

            // Sorting
            $sortField = $request->get('sortField', 'order');
            $sortDirection = $request->get('sortDirection', 'asc');

            // Handle special sorting cases
            if ($sortField === 'parent_name') {
                $query->leftJoin('categories as parent', 'categories.parent_id', '=', 'parent.id')
                      ->orderBy('parent.name', $sortDirection)
                      ->select('categories.*');
            } else {
                $query->orderBy($sortField, $sortDirection);
            }

            // Pagination
            $perPage = (int) $request->get('perPage', 10);
            $categories = $query->paginate($perPage);

            // Transform data
            $categories->getCollection()->transform(function ($category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'description' => $category->description,
                    'image' => $category->image,
                    'parent_id' => $category->parent_id,
                    'parent_name' => $category->parent ? $category->parent->name : null,
                    "products_count" => $category->products()->count(),
                    'order' => $category->order,
                    'is_featured' => $category->is_featured,
                    'created_at' => $category->created_at,
                    'updated_at' => $category->updated_at,
                ];
            });

            return [
                'data' => $categories->items(),
                'pagination' => [
                    'total' => $categories->total(),
                    'perPage' => $categories->perPage(),
                    'currentPage' => $categories->currentPage(),
                    'lastPage' => $categories->lastPage(),
                ],
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $result['data'],
            'pagination' => $result['pagination'],
        ]);
    }

    // Store a newly created category.
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|string',
            'image_file' => 'nullable|image|max:2048',
            'parent_id' => 'nullable|exists:categories,id',
            'order' => 'nullable|integer',
            'is_featured' => 'nullable|boolean|in:0,1,true,false'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();

        // Handle uploaded image
        if ($request->hasFile('image_file')) {
            $file = $request->file('image_file');
            $path = $file->store('categories', 'public');
            $data['image'] = url('storage/' . $path);
        }

        $data['slug'] = Str::slug($data['name']);

        $category = Category::create($data);

        // Clear relevant caches
        $this->clearCategoryCaches();

        return response()->json([
            'success' => true,
            'data' => $category->load('parent')
        ], 201);
    }

    // Display the specified category.
    public function show($slug)
    {
        $cacheKey = "category:{$slug}";

        $category = Cache::remember($cacheKey, $this->cacheDurations['category_detail'], function () use ($slug) {
            return Category::with(['parent', 'children', 'products'])
                ->where('slug', $slug)
                ->first();
        });

        if (!$category) {
            return response()->json([
                'success' => false,
                'message' => 'Category not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $category
        ]);
    }

    // Update the specified category.
    public function update(Request $request, $slug)
    {
        $category = Category::where('slug', $slug)->first();

        if (!$category) {
            return response()->json([
                'success' => false,
                'message' => 'Category not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|string',
            'image_file' => 'nullable|image|max:2048',
            'parent_id' => 'nullable|exists:categories,id',
            'order' => 'nullable|integer',
            'is_featured' => 'nullable|boolean|in:0,1,true,false',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();

        // Handle uploaded image
        if ($request->hasFile('image_file')) {
            $file = $request->file('image_file');
            $path = $file->store('categories', 'public');
            $data['image'] = url('storage/' . $path);
        }

        // Only regenerate slug if name is provided
        if (isset($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $oldSlug = $category->slug;
        $category->update($data);

        // Clear relevant caches
        $this->clearCategoryCaches($oldSlug);
        if (isset($data['slug']) && $data['slug'] !== $oldSlug) {
            $this->clearCategoryCaches($data['slug']);
        }

        return response()->json([
            'success' => true,
            'data' => $category->fresh()->load('parent')
        ]);
    }

    // Delete the specified category.
    public function destroy($slug)
    {
        $category = Category::with('products', 'children')->where('slug', $slug)->first();

        if (!$category) {
            return response()->json([
                'success' => false,
                'message' => 'Category not found'
            ], 404);
        }

        // Check if category has child categories
        if ($category->children()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete category with child categories'
            ], 422);
        }

        // Delete all associated products
        if ($category->products()->count() > 0) {
            foreach ($category->products as $product) {
                $product->delete(); // uses soft delete if Product model uses SoftDeletes
            }
        }

        // Soft delete the category
        $category->forceDelete();

        // Clear relevant caches
        $this->clearCategoryCaches($slug);

        return response()->json([
            'success' => true,
            'message' => 'Category and its associated products deleted successfully'
        ]);
    }

    // Get featured categories
    public function featured()
    {
        $cacheKey = 'categories:featured';

        $categories = Cache::remember($cacheKey, $this->cacheDurations['featured_categories'], function () {
            return Category::with(['parent'])
                ->where('is_featured', true)
                ->orderBy('order')
                ->get();
        });

        return response()->json([
            'success' => true,
            'data' => $categories
        ]);
    }

    // Get product by category
    public function products($slug, Request $request)
    {
        $cacheKey = "category:products:{$slug}:" . md5(serialize($request->all()));

        $result = Cache::remember($cacheKey, $this->cacheDurations['category_products'], function () use ($slug, $request) {
            $category = Category::where('slug', $slug)->first();

            if (!$category) {
                return [
                    'success' => false,
                    'message' => 'Category not found',
                    'status' => 404
                ];
            }

            $query = $category->products()->with('brand', 'images', 'videos');

            // Optional: search filter
            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            }

            // Sorting (default newest first)
            $sortField = $request->get('sortField', 'created_at');
            $sortDirection = $request->get('sortDirection', 'desc');
            $query->orderBy($sortField, $sortDirection);

            // Pagination
            $perPage = (int) $request->get('perPage', 10);
            $products = $query->paginate($perPage);

            return [
                'success' => true,
                'data' => $products->items(),
                'pagination' => [
                    'total' => $products->total(),
                    'perPage' => $products->perPage(),
                    'currentPage' => $products->currentPage(),
                    'lastPage' => $products->lastPage(),
                ]
            ];
        });

        // Handle cached error response
        if (isset($result['status']) && $result['status'] === 404) {
            return response()->json([
                'success' => false,
                'message' => $result['message']
            ], 404);
        }

        return response()->json($result);
    }
}
