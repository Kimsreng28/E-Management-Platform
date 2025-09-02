<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Models\Wishlist;

class WishlistController extends Controller
{
    // Get user wishlist
    public function index(Request $request)
    {
        $user = $request->user();
        $wishlist = Wishlist::with(['product.images'])->where('user_id', $user->id)->get();

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

        return response()->json(['success' => true]);
    }
}
