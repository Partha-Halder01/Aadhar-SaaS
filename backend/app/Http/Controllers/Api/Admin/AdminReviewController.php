<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Services\LandingPageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AdminReviewController extends Controller
{
    /**
     * List all customer reviews for moderation
     */
    public function index(Request $request): JsonResponse
    {
        $query = Review::with('user:id,name,phone,email')->latest();

        if ($request->has('status') && in_array($request->status, ['approved', 'hidden'])) {
            $query->where('is_approved', $request->status === 'approved');
        }

        $reviews = $query->paginate(30);

        return response()->json([
            'status' => 'success',
            'data' => $reviews,
            'summary' => [
                'total' => Review::count(),
                'approved' => Review::where('is_approved', true)->count(),
                'hidden' => Review::where('is_approved', false)->count(),
            ]
        ]);
    }

    /**
     * Toggle approved / hidden status of a review
     */
    public function toggleStatus(int $id): JsonResponse
    {
        $review = Review::findOrFail($id);
        $review->is_approved = !$review->is_approved;
        $review->save();

        Cache::forget(LandingPageService::CACHE_KEY);

        return response()->json([
            'status' => 'success',
            'message' => $review->is_approved ? 'Review is now visible on landing page.' : 'Review has been hidden from landing page.',
            'data' => $review
        ]);
    }

    /**
     * Delete a review
     */
    public function destroy(int $id): JsonResponse
    {
        $review = Review::findOrFail($id);
        $review->delete();

        Cache::forget(LandingPageService::CACHE_KEY);

        return response()->json([
            'status' => 'success',
            'message' => 'Review deleted successfully.'
        ]);
    }
}
