<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Models\User;
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
        $query = Review::with(['user' => function ($q) {
            $q->select('id', 'name', 'phone', 'email', 'status', 'role');
        }])->latest();

        if ($request->has('status') && in_array($request->status, ['approved', 'hidden'])) {
            $query->where('is_approved', $request->status === 'approved');
        }

        if ($request->has('search') && !empty($request->search)) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('author_name', 'like', "%{$s}%")
                  ->orWhere('comment', 'like', "%{$s}%")
                  ->orWhere('role_or_business', 'like', "%{$s}%")
                  ->orWhereHas('user', function ($uq) use ($s) {
                      $uq->where('name', 'like', "%{$s}%")
                         ->orWhere('email', 'like', "%{$s}%")
                         ->orWhere('phone', 'like', "%{$s}%");
                  });
            });
        }

        $perPage = (int) $request->get('per_page', 50);
        $reviews = $query->paginate($perPage);

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

    /**
     * Block or unblock the user who wrote this review
     */
    public function blockUser(Request $request, int $id): JsonResponse
    {
        $review = Review::with('user')->findOrFail($id);
        $user = $review->user;

        if (!$user && $review->user_id) {
            $user = User::find($review->user_id);
        }

        if (!$user) {
            $user = User::where('name', $review->author_name)->first();
        }

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'No registered user account found for this review author.'
            ], 404);
        }

        if ($user->role === 'admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Admin accounts cannot be blocked.'
            ], 422);
        }

        $action = $request->input('action'); // 'block', 'unblock', or toggle
        if ($action === 'block') {
            $user->status = 'blocked';
        } elseif ($action === 'unblock') {
            $user->status = 'active';
        } else {
            $user->status = $user->status === 'active' ? 'blocked' : 'active';
        }

        $user->save();

        if ($user->status === 'blocked') {
            $user->tokens()->delete();
        }

        return response()->json([
            'status' => 'success',
            'message' => "User '{$user->name}' has been {$user->status} successfully.",
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'status' => $user->status,
            ]
        ]);
    }

    /**
     * Block the reviewer and delete the review in one action
     */
    public function blockAndDelete(int $id): JsonResponse
    {
        $review = Review::with('user')->findOrFail($id);
        $user = $review->user;

        if (!$user && $review->user_id) {
            $user = User::find($review->user_id);
        }

        if (!$user) {
            $user = User::where('name', $review->author_name)->first();
        }

        $userBlocked = false;
        $userName = $review->author_name;

        if ($user && $user->role !== 'admin') {
            $user->status = 'blocked';
            $user->save();
            $user->tokens()->delete();
            $userBlocked = true;
            $userName = $user->name;
        }

        $review->delete();
        Cache::forget(LandingPageService::CACHE_KEY);

        $msg = $userBlocked
            ? "Inappropriate comment deleted and user '{$userName}' has been blocked."
            : "Review comment deleted successfully (no registered user account was found to block).";

        return response()->json([
            'status' => 'success',
            'message' => $msg,
            'user_blocked' => $userBlocked,
        ]);
    }
}
