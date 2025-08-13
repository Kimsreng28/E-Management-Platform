<?php

namespace App\Http\Controllers\Api;

use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ReviewController extends Controller
{
    // List all reviews (Admin can filter by approval status)
    public function index(Request $request)
    {
        $query = Review::with(['user', 'product', 'order']);

        if ($request->has('is_approved')) {
            $query->where('is_approved', filter_var($request->is_approved, FILTER_VALIDATE_BOOLEAN));
        }

        $reviews = $query->paginate(15);

        return response()->json($reviews);
    }

    // Show a specific review by id
    public function show(Review $review)
    {
        $review->load(['user', 'product', 'order']);
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

    // Admin updates review (e.g. approval)
    public function update(Request $request, Review $review)
    {
        $validated = $request->validate([
            'rating' => 'sometimes|integer|min:1|max:5',
            'comment' => 'sometimes|string',
            'is_approved' => 'sometimes|boolean',
        ]);

        $review->update($validated);

        return response()->json(['success' => true, 'review' => $review]);
    }

    // Delete a review (Admin or user owner)
    public function destroy(Request $request, Review $review)
    {
        $user = $request->user();

        if ($user->id !== $review->user_id && !$user->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $review->forceDelete();

        return response()->json(['success' => true, 'message' => 'Review deleted']);
    }
}