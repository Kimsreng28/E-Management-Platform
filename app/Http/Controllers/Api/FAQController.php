<?php

namespace App\Http\Controllers\Api;

use Illuminate\Routing\Controller;
use App\Models\FAQ;
use App\Models\FAQCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FAQController extends Controller
{
    /**
     * Display a listing of the FAQs.
     */
    public function index(Request $request)
    {
        try {
            $query = FAQ::with('category')->active()->ordered();

            // Filter by category if provided
            if ($request->has('category_id')) {
                $query->where('category_id', $request->category_id);
            }

            // Search functionality
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('question_en', 'like', "%{$search}%")
                      ->orWhere('question_kh', 'like', "%{$search}%")
                      ->orWhere('answer_en', 'like', "%{$search}%")
                      ->orWhere('answer_kh', 'like', "%{$search}%");
                });
            }

            $faqs = $query->get();

            return response()->json([
                'success' => true,
                'data' => $faqs,
                'message' => 'FAQs retrieved successfully.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve FAQs.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of the FAQ categories.
     */
    public function categories()
    {
        try {
            $categories = FAQCategory::withCount(['faqs' => function($query) {
                $query->active();
            }])->active()->ordered()->get();

            return response()->json([
                'success' => true,
                'data' => $categories,
                'message' => 'FAQ categories retrieved successfully.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve FAQ categories.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created FAQ in storage.
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'question_en' => 'required|string',
                'question_kh' => 'required|string',
                'answer_en' => 'required|string',
                'answer_kh' => 'required|string',
                'category_id' => 'nullable|exists:faq_categories,id',
                'order' => 'integer|min:0',
                'is_active' => 'boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $faq = FAQ::create($validator->validated());

            return response()->json([
                'success' => true,
                'data' => $faq->load('category'),
                'message' => 'FAQ created successfully.'
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create FAQ.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified FAQ.
     */
    public function show($id)
    {
        try {
            $faq = FAQ::with('category')->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $faq,
                'message' => 'FAQ retrieved successfully.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'FAQ not found.',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Update the specified FAQ in storage.
     */
    public function update(Request $request, $id)
    {
        try {
            $faq = FAQ::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'question_en' => 'string',
                'question_kh' => 'string',
                'answer_en' => 'string',
                'answer_kh' => 'string',
                'category_id' => 'nullable|exists:faq_categories,id',
                'order' => 'integer|min:0',
                'is_active' => 'boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $faq->update($validator->validated());

            return response()->json([
                'success' => true,
                'data' => $faq->load('category'),
                'message' => 'FAQ updated successfully.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update FAQ.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified FAQ from storage.
     */
    public function destroy($id)
    {
        try {
            $faq = FAQ::findOrFail($id);
            $faq->delete();

            return response()->json([
                'success' => true,
                'message' => 'FAQ deleted successfully.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete FAQ.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get FAQs by category.
     */
    public function byCategory($categoryId)
    {
        try {
            $faqs = FAQ::with('category')
                ->where('category_id', $categoryId)
                ->active()
                ->ordered()
                ->get();

            return response()->json([
                'success' => true,
                'data' => $faqs,
                'message' => 'FAQs by category retrieved successfully.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve FAQs by category.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Search FAQs.
     */
    public function search(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'query' => 'required|string|min:2'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $query = $request->input('query');

            $faqs = FAQ::with('category')
                ->active()
                ->where(function($q) use ($query) {
                    $q->where('question_en', 'like', "%{$query}%")
                      ->orWhere('question_kh', 'like', "%{$query}%")
                      ->orWhere('answer_en', 'like', "%{$query}%")
                      ->orWhere('answer_kh', 'like', "%{$query}%");
                })
                ->ordered()
                ->get();

            return response()->json([
                'success' => true,
                'data' => $faqs,
                'message' => 'Search results retrieved successfully.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to search FAQs.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
