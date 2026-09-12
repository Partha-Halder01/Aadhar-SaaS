<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PersonalAccessToken;
use App\Models\Review;
use App\Models\User;
use App\Services\LandingPageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ReviewController extends Controller
{
    /**
     * Get approved reviews for display
     */
    public function index(): JsonResponse
    {
        $reviews = Review::where('is_approved', true)
            ->latest()
            ->take(20)
            ->get()
            ->map(function ($r) {
                return [
                    'id' => $r->id,
                    'author' => $r->author_name,
                    'role' => $r->role_or_business ?: 'Verified Customer',
                    'initials' => $r->initials,
                    'stars' => $r->rating,
                    'quote' => $r->comment,
                    'created_at' => $r->created_at->diffForHumans(),
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => $reviews
        ]);
    }

    /**
     * Submit a new review
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'author_name' => 'required|string|min:2|max:100',
            'role_or_business' => 'nullable|string|max:150',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'required|string|min:5|max:1000',
        ]);

        // Attempt to associate logged-in user if token present
        $userId = null;
        $token = $request->bearerToken();
        if ($token) {
            $accessToken = PersonalAccessToken::findToken($token);
            if ($accessToken && $accessToken->tokenable_id) {
                $userId = $accessToken->tokenable_id;
            }
        }

        $authorName = strip_tags(trim($validated['author_name']));
        if ($userId) {
            $user = User::find($userId);
            if ($user) {
                if ($user->status === 'blocked') {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Your account has been deactivated/blocked. You cannot submit reviews.'
                    ], 403);
                }
                $authorName = $user->name;
            }
        }

        $review = Review::create([
            'user_id' => $userId,
            'author_name' => $authorName,
            'role_or_business' => !empty($validated['role_or_business']) ? strip_tags(trim($validated['role_or_business'])) : 'Digital Retailer',
            'rating' => (int) $validated['rating'],
            'comment' => strip_tags(trim($validated['comment'])),
            'is_approved' => false, // Requires admin moderation before public display
            'is_featured' => false,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Thank you! Your review has been submitted and will be published after verification.',
            'data' => [
                'id' => $review->id,
                'author' => $review->author_name,
                'role' => $review->role_or_business,
                'initials' => $review->initials,
                'stars' => $review->rating,
                'quote' => $review->comment,
                'created_at' => 'Under Review',
            ]
        ], 201);
    }
}
