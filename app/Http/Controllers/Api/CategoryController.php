<?php

namespace App\Http\Controllers\Api;


use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    // Display a listing of categories.
    public function index(Request $request)
    {
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
                'order' => $category->order,
                'is_featured' => $category->is_featured,
                'created_at' => $category->created_at,
                'updated_at' => $category->updated_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $categories->items(),
            'pagination' => [
                'total' => $categories->total(),
                'perPage' => $categories->perPage(),
                'currentPage' => $categories->currentPage(),
                'lastPage' => $categories->lastPage(),
            ],
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

        return response()->json([
            'success' => true,
            'data' => $category->load('parent')
        ], 201);
    }

    // Display the specified category.
    public function show($slug)
    {
        $category = Category::with(['parent', 'children', 'products'])
            ->where('slug', $slug)
            ->first();

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

        // Only regenerate slug if explicitly provided
        if (isset($data['slug'])) {
            $data['slug'] = Str::slug($data['slug']);
        }

        $category->update($data);

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

        return response()->json([
            'success' => true,
            'message' => 'Category and its associated products deleted successfully'
        ]);
    }


    // Get featured categories
    public function featured()
    {
        $categories = Category::with(['parent'])
            ->where('is_featured', true)
            ->orderBy('order')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $categories
        ]);
    }
}
