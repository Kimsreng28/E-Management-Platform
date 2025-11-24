<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use App\Models\Wishlist;

class WishlistController extends Controller
{
    // Cache durations in seconds
    private $cacheDurations = [
        'wishlist_data' => 1800, // 30 minutes
    ];

    // Clear wishlist caches for user
    private function clearWishlistCaches($userId)
    {
        Cache::forget("wishlist:{$userId}");
    }

    // Get user wishlist
    public function index(Request $request)
    {
        $user = $request->user();
        $cacheKey = "wishlist:{$user->id}";

        $wishlist = Cache::remember($cacheKey, $this->cacheDurations['wishlist_data'], function () use ($user) {
            return Wishlist::with(['product.images'])->where('user_id', $user->id)->get();
        });

        return response()->json(['wishlist' => $wishlist]);
    }

    // Add product to wishlist
    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
        ]);

        $user = $request->user();

        $wishlist = Wishlist::withTrashed()
            ->firstOrNew([
                'user_id' => $user->id,
                'product_id' => $request->product_id,
            ]);

        if ($wishlist->trashed()) {
            $wishlist->restore();
        }

        $wishlist->save();

        // Clear wishlist cache after update
        $this->clearWishlistCaches($user->id);

        return response()->json(['success' => true, 'wishlist' => $wishlist]);
    }

    // Remove product from wishlist
    public function destroy(Request $request, $productId)
    {
        $user = $request->user();

        $wishlist = Wishlist::where('user_id', $user->id)
            ->where('product_id', $productId)
            ->first();

        if (!$wishlist) {
            return response()->json(['message' => 'Product not in wishlist'], 404);
        }

        $wishlist->delete();

        // Clear wishlist cache after removal
        $this->clearWishlistCaches($user->id);

        return response()->json(['success' => true]);
    }
}
