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
use App\Models\BusinessSetting;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use App\Models\Category;
use App\Models\Brand;
use League\Csv\Reader;
use League\Csv\Writer;
use SplTempFileObject;
use Milon\Barcode\DNS1D;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ProductController extends Controller
{
    // Days to consider a product as new
    private $newProductDays = 14;

    // Admin role
    private function isAdmin()
    {
        return Auth::check() && Auth::user()->role_id === 1;
    }

    // Vendor role
    private function isVendor()
    {
        return Auth::check() && Auth::user()->role_id === 4;
    }

    // Get base vendor query
    private function getBaseQuery()
    {
        $query = Product::with(['category', 'brand', 'images', 'videos', 'vendor']);

        // if vendor
        if ($this->isVendor()) {
            $query->where('vendor_id', Auth::id());
        }

        return $query;
    }

    //Get Qr Code of Unique Product to show it detail
    public function generateProductQrCode($slug, $locale = 'en')
    {
        $product = $this->getBaseQuery()
            ->where('slug', $slug)
            ->firstOrFail();

        // check if vendor has own in this product
        if ($this->isVendor() && $product->vendor_id !== Auth::id()) {
            abort(403, 'Unauthorized access to this product');
        }

        // base url
        $baseUrl = env('NEXT_PUBLIC_FRONTEND_URL');

        // Validate the base URL
        if (empty($baseUrl)) {
            $baseUrl = 'http://localhost:3000';
        }

        // Ensure the base URL doesn't have a trailing slash
        $baseUrl = rtrim($baseUrl, '/');

        // Construct product URL dynamically
        $productUrl = "{$baseUrl}/{$locale}/customer/products/{$product->slug}";

        $qrCode = QrCode::size(300)
            ->format('svg')
            ->generate($productUrl);

        return response($qrCode)->header('Content-Type', 'image/svg+xml');
    }

    //Get product details via QR code
    public function getProductQrDetails($slug)
    {
        $product = $this->getBaseQuery()
            ->where('slug', $slug)
            ->firstOrFail();

        // Check if vendor owns this product
        if ($this->isVendor() && $product->vendor_id !== Auth::id()) {
            abort(403, 'Unauthorized access to this product');
        }

        return response()->json([
            'message' => 'Product details retrieved via QR code',
            'product' => $product,
            'qr_scan_url' => url()->current()
        ]);
    }

    //Get all products with category, brand, images, and videos
    public function getAllProducts(Request $request)
    {
        $query = $this->getBaseQuery();

        if ($this->isVendor()) {
            Log::info('Vendor request', [
                'vendor_id' => Auth::id(),
                'query_params' => $request->all()
            ]);
        }

        // Search (by name, model_code, description, barcode)
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                ->orWhere('model_code', 'ILIKE', "%{$search}%")
                ->orWhere('description', 'ILIKE', "%{$search}%");
            });
        }

        // Filter by Status
        if ($request->filled('status')) {
            $query->where('stock_status', $request->status);
        }

        // Filter by category
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Filter by price range
        if ($request->filled('min_price') || $request->filled('max_price')) {
            $minPrice = $request->filled('min_price') ? $request->min_price : 0;
            $maxPrice = $request->filled('max_price') ? $request->max_price : PHP_FLOAT_MAX;

            $query->whereBetween('price', [$minPrice, $maxPrice]);
        }

        // Filter by brand
        if ($request->filled('brand_id')) {
            $query->where('brand_id', $request->brand_id);
        }

        // Filter by stock
        if ($request->filled('stock')) {
            switch ($request->stock) {
                case 'in_stock':
                    $query->where('stock', '>', 0);
                    break;
                case 'low_stock':
                    $query->where('stock', '>', 0)
                        ->whereColumn('stock', '<=', 'low_stock_threshold');
                    break;
                case 'out_of_stock':
                    $query->where('stock', '<=', 0);
                    break;
            }
        }

        // Filter by featured
        if ($request->filled('is_featured')) {
            $query->where('is_featured', $request->is_featured);
        }

        // Filter by is_new
        if ($request->filled('is_new')) {
            $query->where('created_at', '>=', now()->subDays($this->newProductDays));
        }

        if ($this->isAdmin() && $request->filled('vendor_id')) {
            if ($request->vendor_id === 'null') {
                $query->whereNull('vendor_id'); // Admin products
            } else {
                $query->where('vendor_id', $request->vendor_id); // Specific vendor products
            }
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at'); // default sort column
        $sortOrder = $request->get('sort_order', 'desc'); // default order
        $allowedSorts = ['name', 'price', 'stock', 'created_at'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Pagination
        $perPage = $request->get('per_page', 10); // default 10 per page
        $paginatedProducts = $query->paginate($perPage);

        // Transform products with calculated fields
        $paginatedProducts->getCollection()->transform(function ($product) {
            $product->discount = $product->discount ? (int) round($product->discount) : 0;
            $product->discount_price = round($product->price * (1 - ($product->discount / 100)), 2);
            $product->is_new = $product->created_at >= now()->subDays($this->newProductDays);

            // Rating info
            $avg = $product->reviews()->avg('rating') ?? 0;
            $product->average_rating = round((float) $avg, 1);
            $product->total_ratings = $product->totalRatings();

            return $product;
        });

        return response()->json($paginatedProducts);
    }

    public function getVendorProducts(Request $request)
    {
        // Ensure only vendors can access this route
        if (!$this->isVendor()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access. Vendor role required.'
            ], 403);
        }

        $query = Product::with(['category', 'brand', 'images', 'videos'])
            ->where('vendor_id', Auth::id());

        // Apply all the same filters as getAllProducts
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                ->orWhere('model_code', 'ILIKE', "%{$search}%")
                ->orWhere('description', 'ILIKE', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('stock_status', $request->status);
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('brand_id')) {
            $query->where('brand_id', $request->brand_id);
        }

        if ($request->filled('stock')) {
            switch ($request->stock) {
                case 'in_stock':
                    $query->where('stock', '>', 0);
                    break;
                case 'low_stock':
                    $query->where('stock', '>', 0)
                        ->whereColumn('stock', '<=', 'low_stock_threshold');
                    break;
                case 'out_of_stock':
                    $query->where('stock', '<=', 0);
                    break;
            }
        }

        if ($request->filled('is_featured')) {
            $query->where('is_featured', $request->is_featured);
        }

        if ($request->filled('is_new')) {
            $query->where('created_at', '>=', now()->subDays($this->newProductDays));
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $allowedSorts = ['name', 'price', 'stock', 'created_at'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Pagination
        $perPage = $request->get('per_page', 10);
        $paginatedProducts = $query->paginate($perPage);

        // Transform products with calculated fields
        $paginatedProducts->getCollection()->transform(function ($product) {
            $product->discount = $product->discount ? (int) round($product->discount) : 0;
            $product->discount_price = round($product->price * (1 - ($product->discount / 100)), 2);
            $product->is_new = $product->created_at >= now()->subDays($this->newProductDays);

            // Rating info
            $avg = $product->reviews()->avg('rating') ?? 0;
            $product->average_rating = round((float) $avg, 1);
            $product->total_ratings = $product->totalRatings();

            return $product;
        });

        return response()->json($paginatedProducts);
    }

    /**
     * Create a new product
     */
    public function createProduct(Request $request)
    {
        // Handle specifications if sent as JSON string
        if ($request->has('specifications') && is_string($request->specifications)) {
            $request->merge([
                'specifications' => json_decode($request->specifications, true)
            ]);
        }

        // Validate request data
        $validator = Validator::make($request->all(), [
            'name'              => 'required|string|max:255',
            'model_code'        => 'required|string|max:100|unique:products',
            'stock'             => 'required|integer|min:0',
            'price'             => 'required|numeric|min:0',
            'discount'          => 'nullable|numeric|min:0|max:100',
            'cost_price'        => 'nullable|numeric|min:0',
            'short_description' => 'required|string',
            'description'       => 'required|string',
            'category_id'       => 'required|exists:categories,id',
            'brand_id'          => 'required|exists:brands,id',
            'warranty_months'   => 'nullable|integer|min:0',
            'is_featured'       => 'boolean',
            'is_active'         => 'boolean',
            'specifications'    => 'nullable|array',
            'specifications.*'   => 'string',
            'low_stock_threshold' => 'nullable|integer|min:0',
            'images'            => 'nullable|array|max:5',
            'images.*'          => 'image|mimes:jpg,jpeg,png,webp|max:2048',
            'videos'            => 'nullable|array|max:2',
            'videos.*'          => 'file|mimes:mp4,avi,mov,webm|max:204800',
            'barcode'           => 'nullable|string|unique:products',
        ],[
            'images.max' => 'You can upload a maximum of 5 images per product.',
            'videos.max' => 'You can upload a maximum of 2 videos per product.'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Fetch business settings for current user
        $businessSettings = BusinessSetting::where('user_id', Auth::id())->first();

        // Determine low_stock_threshold (request overrides business setting)
        $threshold = $request->low_stock_threshold
                     ?? ($businessSettings->low_stock_threshold ?? 10);

        $stock = $request->stock;

        if ($stock <= 0) {
            $stock_status = 'Out of Stock';
        } elseif ($stock <= $threshold) {
            $stock_status = 'Inactive';
        } else {
            $stock_status = 'Active';
        }

        $productData = [
            'name'              => $request->name,
            'model_code'        => $request->model_code,
            'slug'              => Str::slug($request->name) . '-' . uniqid(),
            'stock'             => $stock,
            'price'             => $request->price,
            'discount'          => $request->discount ? (int) round($request->discount) : 0,
            'cost_price'        => $request->cost_price,
            'short_description' => $request->short_description,
            'description'       => $request->description,
            'category_id'       => $request->category_id,
            'brand_id'          => $request->brand_id,
            'warranty_months'   => $request->warranty_months,
            'is_featured'       => $request->is_featured ?? false,
            'is_active'         => $request->is_active ?? true,
            'specifications'    => $request->specifications,
            'created_by'        => Auth::id() ?? 1,
            'low_stock_threshold' => $threshold,
            'stock_status'      => $stock_status,
            'barcode'           => $request->barcode ?? Product::generateUniqueBarcode(),
        ];

        // If user is vendor, set vendor_id
        if ($this->isVendor()) {
            $productData['vendor_id'] = Auth::id();
        }

        $product = Product::create($productData);

        // Save product images
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $index => $file) {
                $path = $file->store('products/images', 'public');

                ProductImage::create([
                    'product_id' => $product->id,
                    'path'       => 'storage/' . $path,
                    'alt_text'   => null,
                    'order'      => $index,
                    'is_primary' => $index === 0,
                ]);
            }
        }

        // Save product videos
        if ($request->hasFile('videos')) {
            foreach ($request->file('videos') as $index => $video) {
                $path = $video->store('products/videos', 'public');

                ProductVideo::create([
                    'product_id' => $product->id,
                    'url'        => '/storage/' . $path,
                    'thumbnail'  => null,
                    'title'      => $video->getClientOriginalName(),
                    'duration'   => null,
                ]);
            }
        }

        $product->load(['images', 'videos', 'category', 'brand']);

        $product->is_new = $product->created_at >= now()->subDays($this->newProductDays);
        $discountPrice = round($product->price * (1 - ($product->discount / 100)), 2);

        return response()->json([
            'message' => 'Product created successfully',
            'product' => $product->load(['images', 'videos', 'category', 'brand']),
            'discount_price' => $discountPrice
        ], 201);
    }

    /**
     * Export products to CSV
     */
    public function exportProducts(Request $request)
    {
        $query = Product::with(['category', 'brand']);

        // Apply filters if any
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('brand_id')) {
            $query->where('brand_id', $request->brand_id);
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->is_active);
        }

        $products = $query->get();

        // Check if any products exist
        if ($products->isEmpty()) {
            return response()->json([
                'message' => 'No products found for export',
                'error' => 'No products match the specified criteria'
            ], 404);
        }

        // Create CSV
        $csv = Writer::createFromFileObject(new SplTempFileObject());
        $csv->insertOne([
            'Name', 'Model Code', 'Barcode', 'Slug', 'Stock', 'Price', 'Cost Price',
            'Discount', 'Short Description', 'Description', 'Category', 'Brand',
            'Warranty Months', 'Is Featured', 'Is Active', 'Specifications',
            'Low Stock Threshold', 'Stock Status'
        ]);

        foreach ($products as $product) {
            $csv->insertOne([
                $product->name,
                $product->model_code,
                $product->barcode,
                $product->slug,
                $product->stock,
                $product->price,
                $product->cost_price,
                $product->discount,
                $product->short_description,
                $product->description,
                $product->category->name ?? '',
                $product->brand->name ?? '',
                $product->warranty_months,
                $product->is_featured ? 'Yes' : 'No',
                $product->is_active ? 'Yes' : 'No',
                json_encode($product->specifications),
                $product->low_stock_threshold,
                $product->stock_status,
            ]);
        }

        $csvContent = (string) $csv;
        $filename = 'products_export_' . date('Y-m-d_H-i-s') . '.csv';

        return response($csvContent, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Import products from CSV
     */
    public function importProducts(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'csv_file' => 'required|file|mimes:csv,txt|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $file = $request->file('csv_file');
        $csv = Reader::createFromPath($file->getPathname(), 'r');
        $csv->setHeaderOffset(0);

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($csv as $index => $row) {
            try {
                // Validate required fields
                if (empty($row['Name']) || empty($row['Model Code']) || empty($row['Category']) || empty($row['Brand'])) {
                    $errors[] = "Row $index: Missing required fields";
                    $skipped++;
                    continue;
                }

                // Find category
                $category = Category::where('name', $row['Category'])->first();
                if (!$category) {
                    $errors[] = "Row $index: Category '{$row['Category']}' not found";
                    $skipped++;
                    continue;
                }

                // Find brand
                $brand = Brand::where('name', $row['Brand'])->first();
                if (!$brand) {
                    $errors[] = "Row $index: Brand '{$row['Brand']}' not found";
                    $skipped++;
                    continue;
                }

                // Check if product already exists
                if (Product::where('model_code', $row['Model Code'])->exists()) {
                    $errors[] = "Row $index: Product with model code '{$row['Model Code']}' already exists";
                    $skipped++;
                    continue;
                }

                // Parse specifications if provided
                $specifications = [];
                if (!empty($row['Specifications'])) {
                    $specifications = json_decode($row['Specifications'], true) ?? [];
                }

                // Create product
                Product::create([
                    'name' => $row['Name'],
                    'model_code' => $row['Model Code'],
                    'barcode' => $row['Barcode'] ?? Product::generateUniqueBarcode(),
                    'slug' => Str::slug($row['Name']) . '-' . uniqid(),
                    'stock' => $row['Stock'] ?? 0,
                    'price' => $row['Price'] ?? 0,
                    'cost_price' => $row['Cost Price'] ?? null,
                    'discount' => $row['Discount'] ?? 0,
                    'short_description' => $row['Short Description'] ?? '',
                    'description' => $row['Description'] ?? '',
                    'category_id' => $category->id,
                    'brand_id' => $brand->id,
                    'warranty_months' => $row['Warranty Months'] ?? null,
                    'is_featured' => strtolower($row['Is Featured'] ?? 'no') === 'yes',
                    'is_active' => strtolower($row['Is Active'] ?? 'yes') === 'yes',
                    'specifications' => $specifications,
                    'created_by' => Auth::id(),
                    'low_stock_threshold' => $row['Low Stock Threshold'] ?? 10,
                    'stock_status' => $row['Stock Status'] ?? 'Active',
                ]);

                $imported++;
            } catch (\Exception $e) {
                $errors[] = "Row $index: " . $e->getMessage();
                $skipped++;
            }
        }

        return response()->json([
            'message' => 'Import completed',
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);
    }

    /**
     * Generate barcode for a product
     */
    public function generateBarcode($slug)
    {
        $product = Product::where('slug', $slug)->firstOrFail();

        if (!$product->barcode) {
            $product->barcode = Product::generateUniqueBarcode();
            $product->save();
        }

        // Generate base64 barcode (Code128)
        $barcode = new DNS1D();
        $barcode->setStorPath(storage_path('products/barcodes/'));

        $barcodeBase64 = $barcode->getBarcodePNG($product->barcode, 'C128', 3, 100);

        return response()->json([
            'barcode' => $product->barcode,
            'image'   => 'data:image/png;base64,' . $barcodeBase64,
        ]);
    }

    /**
     * Get product by barcode
     */
    public function getProductByBarcode($barcode)
    {
        $product = Product::with(['category', 'brand', 'images', 'videos'])
            ->where('barcode', $barcode)
            ->firstOrFail();

        // Calculate dynamic fields
        $product->discount_price = round($product->price * (1 - ($product->discount / 100)), 2);
        $product->is_new = $product->created_at >= now()->subDays($this->newProductDays);

        // Rating info
        $avg = $product->reviews()->avg('rating') ?? 0;
        $product->average_rating = round((float) $avg, 1);
        $product->total_ratings = $product->totalRatings();

        return response()->json($product);
    }

    /**
     * Get single product by slug
     */
    public function getProduct($slug)
    {
        $product = $this->getBaseQuery()
            ->with(['category', 'brand', 'images', 'videos', 'orders.shippingAddress'])
            ->where('slug', $slug)
            ->firstOrFail();

        // Check if vendor owns this product
        if ($this->isVendor() && $product->vendor_id !== Auth::id()) {
            abort(403, 'Unauthorized access to this product');
        }

        // Calculate dynamic fields
        $product->discount_price = round($product->price * (1 - ($product->discount / 100)), 2);
        $product->is_new = $product->created_at >= now()->subDays($this->newProductDays);

        // Rating info
        $avg = $product->reviews()->avg('rating') ?? 0;
        $product->average_rating = round((float) $avg, 1);
        $product->total_ratings = $product->totalRatings();

        return response()->json($product);
    }

    /**
     * Update product by slug
     */
    public function updateProduct(Request $request, $slug)
    {
        $product = $this->getBaseQuery()->where('slug', $slug)->firstOrFail();

        // Check if vendor owns this product
        if ($this->isVendor() && $product->vendor_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this product'
            ], 403);
        }

        // Handle specifications if sent as JSON string
        if ($request->has('specifications') && is_string($request->specifications)) {
            try {
                $request->merge([
                    'specifications' => json_decode($request->specifications, true)
                ]);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid specifications format'
                ], 422);
            }
        }

        $validator = Validator::make($request->all(), [
            'name'              => 'sometimes|string|max:255',
            'model_code'        => 'sometimes|string|max:100|unique:products,model_code,' . $product->id,
            'stock'             => 'sometimes|integer|min:0',
            'price'             => 'sometimes|numeric|min:0',
            'discount'          => 'nullable|numeric|min:0|max:100',
            'cost_price'        => 'nullable|numeric|min:0',
            'short_description' => 'sometimes|string',
            'description'       => 'sometimes|string',
            'category_id'       => 'sometimes|exists:categories,id',
            'brand_id'          => 'sometimes|exists:brands,id',
            'warranty_months'   => 'nullable|integer|min:0',
            'is_featured'       => 'sometimes|boolean',
            'is_active'         => 'sometimes|boolean',
            'specifications'    => 'nullable|array',
            'specifications.*.key' => 'sometimes|string',
            'specifications.*.value' => 'sometimes|string',
            'low_stock_threshold' => 'nullable|integer|min:0',
            'images'            => 'nullable|array|max:5',
            'images.*'          => 'image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'videos'            => 'nullable|array|max:2',
            'videos.*'          => 'file|mimes:mp4,mov,avi,webm|max:102400',
            'images_to_delete'  => 'nullable|array',
            'images_to_delete.*' => 'integer',
            'videos_to_delete'  => 'nullable|array',
            'videos_to_delete.*' => 'integer',
            'barcode'           => 'nullable|string|unique:products,barcode,' . $product->id,
        ], [
            'images.max' => 'You can upload a maximum of 5 images per product.',
            'videos.max' => 'You can upload a maximum of 2 videos per product.',
            'images.*.max' => 'Each image must not be larger than 2MB.',
            'videos.*.max' => 'Each video must not be larger than 100MB.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $request->only([
            'name', 'model_code', 'stock', 'price', 'discount', 'cost_price',
            'short_description', 'description', 'category_id', 'brand_id',
            'warranty_months', 'is_featured', 'is_active', 'specifications',
            'low_stock_threshold', 'barcode'
        ]);

        // Update slug if name changes
        if (isset($data['name']) && $data['name'] !== $product->name) {
            $data['slug'] = Str::slug($data['name']) . '-' . uniqid();
        }

        // Handle discount conversion
        if (isset($data['discount'])) {
            $data['discount'] = (int) round($data['discount']);
        }

        // Fetch business settings for threshold fallback
        $businessSettings = BusinessSetting::where('user_id', Auth::id())->first();
        $newThreshold = $data['low_stock_threshold'] ?? $product->low_stock_threshold ?? ($businessSettings->low_stock_threshold ?? 10);
        $newStock = $data['stock'] ?? $product->stock;

        // Recalculate stock status
        if (isset($data['stock']) || isset($data['low_stock_threshold'])) {
            if ($newStock <= 0) {
                $data['stock_status'] = 'Out of Stock';
            } elseif ($newStock <= $newThreshold) {
                $data['stock_status'] = 'Low Stock';
            } else {
                $data['stock_status'] = 'In Stock';
            }
            $data['low_stock_threshold'] = $newThreshold;
        }

        // Handle image deletions
        if ($request->has('images_to_delete')) {
            foreach ($request->images_to_delete as $imageId) {
                $image = ProductImage::where('id', $imageId)
                    ->where('product_id', $product->id)
                    ->first();

                if ($image) {
                    // Convert "storage/products/..." to "public/products/..."
                    $storagePath = str_replace('storage/', 'public/', $image->path);
                    if (Storage::exists($storagePath)) {
                        Storage::delete($storagePath);
                    }
                    $image->delete();
                }
            }
        }

        // Handle video deletions
        if ($request->has('videos_to_delete')) {
            foreach ($request->videos_to_delete as $videoId) {
                $video = ProductVideo::where('id', $videoId)
                    ->where('product_id', $product->id)
                    ->first();

                if ($video) {
                    // Delete file from storage (remove leading slash if present)
                    $videoPath = ltrim($video->url, '/');
                    if (Storage::exists($videoPath)) {
                        Storage::delete($videoPath);
                    }
                    $video->delete();
                }
            }
        }

        // Handle new image uploads
        if ($request->hasFile('images')) {
            $currentImageCount = $product->images()->count();
            foreach ($request->file('images') as $index => $image) {
                $path = $image->store('products/images', 'public');

                ProductImage::create([
                    'product_id' => $product->id,
                    'path'       => 'storage/' . $path,
                    'is_primary' => $currentImageCount === 0 && $index === 0, // Set as primary only if no images exist
                    'order'      => $currentImageCount + $index,
                ]);
            }
        }

        // Handle new video uploads
        if ($request->hasFile('videos')) {
            foreach ($request->file('videos') as $video) {
                $path = $video->store('products/videos', 'public');

                ProductVideo::create([
                    'product_id' => $product->id,
                    'url'        => '/storage/' . $path,
                    'title'      => $video->getClientOriginalName(),
                ]);
            }
        }

        // Update the product
        $product->update($data);

        // Reload relationships
        $product->load(['images', 'videos', 'category', 'brand']);

        // Calculate dynamic fields for response
        $product->discount_price = round($product->price * (1 - ($product->discount / 100)), 2);
        $product->is_new = $product->created_at >= now()->subDays($this->newProductDays);

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully',
            'data' => $product
        ]);
    }

    /**
     * Delete product by slug
     */
    public function deleteProduct($slug)
    {
        $product = $this->getBaseQuery()->where('slug', $slug)->firstOrFail();

        // Check if vendor owns this product
        if ($this->isVendor() && $product->vendor_id !== Auth::id()) {
            abort(403, 'Unauthorized access to this product');
        }

        $product->forceDelete();

        return response()->json(['message' => 'Product deleted successfully']);
    }

    public function getProductStats()
    {
        $query = Product::query();

        // If user is vendor, only count their products
        if ($this->isVendor()) {
            $query->where('vendor_id', Auth::id());
        }

        $stats = [
            'total_products' => $query->count(),
            'active_products' => $query->where('is_active', true)->count(),
            'low_stock_products' => $query->where('stock', '>', 0)
                ->whereColumn('stock', '<=', 'low_stock_threshold')
                ->count(),
            'out_of_stock_products' => $query->where('stock', '<=', 0)->count(),
        ];

        return response()->json($stats);
    }

    public function getVendorProductsStats()
    {
        $stats = [
            'total_products' => Product::where('vendor_id', Auth::id())->count(),
            'active_products' => Product::where('vendor_id', Auth::id())->where('is_active', true)->count(),
            'low_stock_products' => Product::where('vendor_id', Auth::id())
                ->where('stock', '>', 0)
                ->whereColumn('stock', '<=', 'low_stock_threshold')
                ->count(),
            'out_of_stock_products' => Product::where('vendor_id', Auth::id())->where('stock', '<=', 0)->count(),
        ];

        return response()->json($stats);
    }

    public function getByCategory(Request $request, $categoryId)
    {
        $query = $this->getBaseQuery()
            ->with(['category', 'brand', 'images'])
            ->where('category_id', $categoryId)
            ->where('is_active', true);

        if ($request->has('exclude')) {
            $query->where('id', '!=', $request->exclude);
        }

        if ($request->has('limit')) {
            $query->limit($request->limit);
        }

        $products = $query->get();

        return response()->json($products);
    }

    // Get popular products
    public function getPopularProducts(Request $request)
    {
        $limit = $request->get('limit', 10);
        $products = Product::popular($limit)->with(['images', 'category', 'brand'])->get();

        // Add calculated fields
        $products->transform(function ($product) {
            $product->discount_price = round($product->price * (1 - ($product->discount / 100)), 2);
            $product->is_new = $product->created_at >= now()->subDays($this->newProductDays);

            // Rating info
            $avg = $product->reviews()->avg('rating') ?? 0;
            $product->average_rating = round((float) $avg, 1);
            $product->total_ratings = $product->totalRatings();

            return $product;
        });

        return response()->json($products);
    }

    // Get recommended products
    public function getRecommendedProducts(Request $request)
    {
        $limit = $request->get('limit', 10);
        $categoryId = $request->get('category_id');

        $products = Product::recommended($categoryId, $limit)->with(['images', 'category', 'brand'])->get();

        if ($products->isEmpty()) {
            return response()->json([
                'message' => 'No recommended products found',
                'data' => []
            ]);
        }

        // Add calculated fields
        $products->transform(function ($product) {
            $product->discount_price = round($product->price * (1 - ($product->discount / 100)), 2);
            $product->is_new = $product->created_at >= now()->subDays($this->newProductDays);

            $avg = $product->reviews()->avg('rating') ?? 0;
            $product->average_rating = round((float) $avg, 1);
            $product->total_ratings = $product->totalRatings();

            return $product;
        });

        return response()->json($products);
    }

    // Get highly rated products
    public function getHighlyRatedProducts(Request $request)
    {
        $limit = $request->get('limit', 10);
        $minRating = $request->get('min_rating', 4);

        $products = Product::highlyRated($minRating, $limit)->with(['images', 'category', 'brand'])->get();

        // Add calculated fields
        $products->transform(function ($product) {
            $product->discount_price = round($product->price * (1 - ($product->discount / 100)), 2);
            $product->is_new = $product->created_at >= now()->subDays($this->newProductDays);

            $avg = $product->reviews()->avg('rating') ?? 0;
            $product->average_rating = round((float) $avg, 1);
            $product->total_ratings = $product->totalRatings();

            return $product;
        });

        return response()->json($products);
    }

    // Get products reviewed by current user
    public function getUserReviewedProducts(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $products = Product::reviewedByUser($user->id)
            ->with(['category', 'brand', 'images'])
            ->get();

        // Add calculated fields
        $products->transform(function ($product) {
            $product->discount_price = round($product->price * (1 - ($product->discount / 100)), 2);
            $product->is_new = $product->created_at >= now()->subDays($this->newProductDays);

            // Rating info
            $avg = $product->reviews()->avg('rating') ?? 0;
            $product->average_rating = round((float) $avg, 1);
            $product->total_ratings = $product->totalRatings();

            return $product;
        });

        return response()->json($products);
    }
}
