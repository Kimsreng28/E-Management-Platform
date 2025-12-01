<?php

namespace App\Http\Controllers\Api;

use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class ReviewController extends Controller
{
    // List all reviews (Admin can filter by approval status)
    public function index(Request $request)
    {
        $query = Review::with(['user', 'product', 'order', 'replies.user']);

        if ($request->has('is_approved')) {
            $query->where('is_approved', filter_var($request->is_approved, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        $reviews = $query->orderBy('created_at', 'desc')->paginate(15);

        return response()->json($reviews);
    }

    // List reviews for VENDOR (only their products)
    public function vendorReviews(Request $request)
    {
        $user = $request->user();

        // Check if user is vendor
        if (!$user->isVendor()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Vendor access only.'
            ], 403);
        }

        $query = Review::with(['user', 'product', 'order', 'replies.user'])
            ->whereHas('product', function($q) use ($user) {
                $q->where('vendor_id', $user->id);
            });

        if ($request->has('is_approved')) {
            $query->where('is_approved', filter_var($request->is_approved, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        $reviews = $query->orderBy('created_at', 'desc')->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $reviews->items(),
            'meta' => [
                'current_page' => $reviews->currentPage(),
                'last_page' => $reviews->lastPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total(),
            ]
        ]);
    }

    // Show a specific review by id
    public function show(Review $review)
    {
        $review->load(['user', 'product', 'order', 'replies.user']);
        return response()->json($review);
    }

    // Customer creates a review (is_approved = false by default)
    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'order_id' => 'nullable|exists:orders,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'required|string',
        ]);

        $user = $request->user();
        $validated['user_id'] = $user->id;
        $validated['is_approved'] = false;

        $review = Review::create($validated);

        return response()->json(['success' => true, 'review' => $review], 201);
    }

    // Vendor/Admin replies to a review
    public function reply(Request $request, Review $review)
    {
        $user = $request->user();

        // Check if user is authenticated
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        // Check if user owns the product or is admin
        if ($user->role_id !== 1 && $review->product->vendor_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. You can only reply to reviews of your own products.'
            ], 403);
        }

        // Validate the request
        $validated = $request->validate([
            'comment' => 'required|string|max:1000',
        ]);

        try {
            // Create the reply
            $reply = $review->replies()->create([
                'user_id' => $user->id,
                'comment' => $validated['comment'],
                'is_vendor_reply' => true,
            ]);

            // Load the user relationship for the response
            $reply->load('user');

            return response()->json([
                'success' => true,
                'message' => 'Reply added successfully',
                'data' => $reply
            ], 201);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to add reply: ' . $e->getMessage()
            ], 500);
        }
    }

    // Admin/Vendor updates review (e.g. approval)
    public function update(Request $request, Review $review)
    {
        $user = $request->user();

        // Vendors can only approve reviews for their products
        if ($user->isVendor() && $review->product->vendor_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'rating' => 'sometimes|integer|min:1|max:5',
            'comment' => 'sometimes|string',
            'is_approved' => 'sometimes|boolean',
        ]);

        $review->update($validated);

        return response()->json(['success' => true, 'review' => $review->load(['user', 'replies.user'])]);
    }

    // Delete a review (Admin or user owner)
    public function destroy(Request $request, Review $review)
    {
        $user = $request->user();

        if ($user->id !== $review->user_id && !$user->hasRole('admin') && $review->product->vendor_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $review->forceDelete();

        return response()->json(['success' => true, 'message' => 'Review deleted']);
    }

    // Delete a reply
    public function deleteReply(Request $request, Review $review, $replyId)
    {
        $user = $request->user();
        $reply = $review->replies()->findOrFail($replyId);

        // Only the reply owner or product vendor can delete
        if ($user->id !== $reply->user_id && $review->product->vendor_id !== $user->id && !$user->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $reply->delete();

        return response()->json(['success' => true, 'message' => 'Reply deleted']);
    }

    public function getByProduct(Request $request, $productId)
    {
        $query = Review::with(['user', 'replies.user'])
            ->where('product_id', $productId)
            ->where('is_approved', true);

        $reviews = $query->get();

        return response()->json($reviews);
    }

    public function recent(Request $request)
    {
        try {
            $limit = $request->get('limit', 6);

            $reviews = Review::with(['user:id,name,email', 'product:id,name,slug'])
                ->where('is_approved', true)
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Recent reviews retrieved successfully',
                'data' => $reviews
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve recent reviews',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Get review statistics for vendor
    public function stats(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        try {
            // Get reviews for vendor's products
            $reviews = Review::whereHas('product', function($q) use ($user) {
                $q->where('vendor_id', $user->id);
            })->get();

            $totalReviews = $reviews->count();

            if ($totalReviews === 0) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'total_reviews' => 0,
                        'pending_reviews' => 0,
                        'average_rating' => 0,
                        'rating_distribution' => [0, 0, 0, 0, 0] // 1-5 stars
                    ]
                ]);
            }

            $pendingReviews = $reviews->where('is_approved', false)->count();

            // Calculate average rating excluding null/0 ratings
            $ratings = $reviews->pluck('rating')->filter(function($rating) {
                return !is_null($rating) && $rating > 0;
            });

            $averageRating = $ratings->isNotEmpty() ? $ratings->avg() : 0;

            // Calculate rating distribution
            $ratingDistribution = [
                '1_star' => $reviews->where('rating', 1)->count(),
                '2_star' => $reviews->where('rating', 2)->count(),
                '3_star' => $reviews->where('rating', 3)->count(),
                '4_star' => $reviews->where('rating', 4)->count(),
                '5_star' => $reviews->where('rating', 5)->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'total_reviews' => $totalReviews,
                    'pending_reviews' => $pendingReviews,
                    'average_rating' => round($averageRating, 1),
                    'rating_distribution' => $ratingDistribution,
                    'reviews_with_ratings' => $ratings->count()
                ]
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Error fetching statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function adminStats(Request $request)
    {
        $user = $request->user();

        // Check if user is admin
        if ($user->role_id !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Admin access only.'
            ], 403);
        }

        $totalReviews = Review::count();
        $pendingReviews = Review::where('is_approved', false)->count();
        $averageRating = Review::avg('rating');

        return response()->json([
            'success' => true,
            'data' => [
                'total_reviews' => $totalReviews,
                'pending_reviews' => $pendingReviews,
                'average_rating' => round($averageRating, 1),
            ]
        ]);
    }
}
