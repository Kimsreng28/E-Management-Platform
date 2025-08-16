<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVideo;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    //Get all products with category, brand, images, and videos
    public function getAllProducts()
    {
        $products = Product::with(['category', 'brand', 'images', 'videos'])->get();
        return response()->json($products);
    }

    /**
     * Create a new product
     */
    public function createProduct(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'              => 'required|string|max:255',
            'model_code'        => 'required|string|max:100|unique:products',
            'stock'             => 'required|integer|min:0',
            'price'             => 'required|numeric|min:0',
            'cost_price'        => 'nullable|numeric|min:0',
            'short_description' => 'required|string',
            'description'       => 'required|string',
            'category_id'       => 'required|exists:categories,id',
            'brand_id'          => 'required|exists:brands,id',
            'warranty_months'   => 'nullable|integer|min:0',
            'is_featured'       => 'boolean',
            'is_active'         => 'boolean',
            'specifications'    => 'nullable|array',
            'images'            => 'nullable|array|max:5',
            'images.*'          => 'image|mimes:jpg,jpeg,png,webp|max:2048',
            'videos'            => 'nullable|array|max:2',
            'videos.*'          => 'file|mimes:mp4,avi,mov,webm|max:204800',
        ],[
            'images.max' => 'You can upload a maximum of 5 images per product.',
            'videos.max' => 'You can upload a maximum of 5 videos per product.'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $product = Product::create([
            'name'              => $request->name,
            'model_code'        => $request->model_code,
            'slug'              => Str::slug($request->name) . '-' . uniqid(),
            'stock'             => $request->stock,
            'price'             => $request->price,
            'cost_price'        => $request->cost_price,
            'short_description' => $request->short_description,
            'description'       => $request->description,
            'category_id'       => $request->category_id,
            'brand_id'          => $request->brand_id,
            'warranty_months'   => $request->warranty_months,
            'is_featured'       => $request->is_featured ?? false,
            'is_active'         => $request->is_active ?? true,
            'specifications'    => $request->specifications,
            'created_by'        => Auth::id() ?? 1 // fallback to user id 1
        ]);

        // Save product images
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $index => $file) {
                $path = $file->store('products/images', 'public'); // saved in storage/app/public/products

                ProductImage::create([
                    'product_id' => $product->id,
                    'path'       => 'storage/' . $path, // public path
                    'alt_text'   => null,
                    'order'      => $index,
                    'is_primary' => $index === 0, // first image is primary
                ]);
            }
        }

        // Save product videos
        if ($request->hasFile('videos')) {
            foreach ($request->file('videos') as $index => $video) {
                $path = $video->store('products/videos', 'public'); // saves in storage/app/public/products/videos

                ProductVideo::create([
                    'product_id' => $product->id,
                    'url'        => '/storage/' . $path, // public URL
                    'thumbnail'  => null, // you can generate a thumbnail later
                    'title'      => $video->getClientOriginalName(),
                    'duration'   => null // optional, can be extracted with FFmpeg
                ]);
            }
        }

        return response()->json([
            'message' => 'Product created successfully',
            'product' => $product->load(['images', 'videos', 'category', 'brand'])
        ], 201);
    }

    /**
     * Get single product by slug
     */
    public function getProduct($slug)
    {
        $product = Product::with(['category', 'brand', 'images', 'videos'])->where('slug', $slug)->firstOrFail();
        return response()->json($product);
    }

    /**
     * Update product by slug
     */
    public function updateProduct(Request $request, $slug)
    {
        $product = Product::where('slug', $slug)->firstOrFail();

        $validator = Validator::make($request->all(), [
            'name'              => 'sometimes|string|max:255',
            'model_code'        => 'sometimes|string|max:100|unique:products,model_code,' . $product->id,
            'stock'             => 'sometimes|integer|min:0',
            'price'             => 'sometimes|numeric|min:0',
            'cost_price'        => 'nullable|numeric|min:0',
            'short_description' => 'sometimes|string',
            'description'       => 'sometimes|string',
            'category_id'       => 'sometimes|exists:categories,id',
            'brand_id'          => 'sometimes|exists:brands,id',
            'warranty_months'   => 'nullable|integer|min:0',
            'is_featured'       => 'boolean',
            'is_active'         => 'boolean',
            'specifications'    => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $data = $request->all();
        if (isset($data['name'])) {
            $data['slug'] = Str::slug($data['name']) . '-' . uniqid();
        }

        $product->update($data);

        return response()->json(['message' => 'Product updated successfully', 'product' => $product]);
    }

    /**
     * Delete product by slug
     */
    public function deleteProduct($slug)
    {
        $product = Product::where('slug', $slug)->firstOrFail();
        $product->forceDelete();
        return response()->json(['message' => 'Product deleted successfully']);
    }
}
