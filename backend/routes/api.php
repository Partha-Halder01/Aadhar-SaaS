<?php

use App\Http\Controllers\Api\Admin\AdminComplaintController;
use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\Admin\AdminLandingPageController;
use App\Http\Controllers\Api\Admin\AdminOrderController;
use App\Http\Controllers\Api\Admin\AdminReviewController;
use App\Http\Controllers\Api\Admin\AdminServiceCategoryController;
use App\Http\Controllers\Api\Admin\AdminServiceController;
use App\Http\Controllers\Api\Admin\AdminSettingController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\AdminWalletController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ComplaintController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\LandingPageController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

// Auth (Rate limited to 10 requests per minute)
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/auth/send-otp', [AuthController::class, 'sendOtp']);
    Route::post('/auth/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/login-otp', [AuthController::class, 'loginWithOtp']);
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
});

// Public Services & Public Portal Settings
Route::get('/services', [ServiceController::class, 'index']);
Route::get('/service-categories', [ServiceController::class, 'categories']);
Route::get('/services/{id}', [ServiceController::class, 'show']);
Route::get('/settings/public', [SettingController::class, 'publicSettings']);
Route::get('/landing-page', [LandingPageController::class, 'index']);
Route::get('/reviews', [ReviewController::class, 'index']);
Route::post('/reviews', [ReviewController::class, 'store'])->middleware('throttle:5,1');

/*
|--------------------------------------------------------------------------
| Protected Customer Routes (api.auth)
|--------------------------------------------------------------------------
*/
Route::middleware('api.auth')->group(function () {
    // Auth & Profile
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::put('/auth/change-password', [AuthController::class, 'changePassword']);

    // Orders
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::get('/orders/{id}/download/{type?}', [OrderController::class, 'download']);
    Route::post('/orders/{id}/submit-document', [OrderController::class, 'submitDocument']);

    // Wallet
    Route::get('/wallet', [WalletController::class, 'index']);
    Route::post('/wallet/recharge', [WalletController::class, 'recharge']);

    // Documents
    Route::get('/documents', [DocumentController::class, 'index']);

    // Complaints / Support Tickets
    Route::get('/complaints', [ComplaintController::class, 'index']);
    Route::post('/complaints', [ComplaintController::class, 'store']);
});

/*
|--------------------------------------------------------------------------
| Protected Admin Routes (api.auth & role:admin)
|--------------------------------------------------------------------------
*/
Route::middleware(['api.auth', 'role:admin'])->prefix('admin')->group(function () {
    // Dashboard Stats
    Route::get('/stats', [AdminDashboardController::class, 'stats']);

    // Manage Orders & Fulfillment
    Route::get('/orders', [AdminOrderController::class, 'index']);
    Route::get('/orders/{id}', [AdminOrderController::class, 'show']);
    Route::post('/orders/{id}/verify-payment', [AdminOrderController::class, 'verifyPayment']);
    Route::post('/orders/{id}/fulfill', [AdminOrderController::class, 'fulfill']);
    Route::post('/orders/{id}/mark-printed', [AdminOrderController::class, 'markPrinted']);
    Route::post('/orders/{id}/request-document', [AdminOrderController::class, 'requestDocument']);

    // Manage Wallet Requests
    Route::get('/wallet-requests', [AdminWalletController::class, 'index']);
    Route::post('/wallet-requests/{id}/process', [AdminWalletController::class, 'process']);

    // Manage Users
    Route::get('/users', [AdminUserController::class, 'index']);
    Route::post('/users/{id}/toggle-status', [AdminUserController::class, 'toggleStatus']);
    Route::post('/users/{id}/adjust-balance', [AdminUserController::class, 'adjustBalance']);

    // Manage Services & Categories
    Route::get('/services', [AdminServiceController::class, 'index']);
    Route::get('/services/categories', [AdminServiceController::class, 'categories']);
    Route::post('/services', [AdminServiceController::class, 'store']);
    Route::match(['put', 'post'], '/services/{id}', [AdminServiceController::class, 'update']);
    Route::post('/services/{id}/toggle', [AdminServiceController::class, 'toggle']);

    Route::get('/service-categories', [AdminServiceCategoryController::class, 'index']);
    Route::post('/service-categories', [AdminServiceCategoryController::class, 'store']);
    Route::match(['put', 'post'], '/service-categories/{id}', [AdminServiceCategoryController::class, 'update']);
    Route::post('/service-categories/{id}/toggle', [AdminServiceCategoryController::class, 'toggle']);

    // Manage Complaints
    Route::get('/complaints', [AdminComplaintController::class, 'index']);
    Route::post('/complaints/{id}/reply', [AdminComplaintController::class, 'reply']);

    // Manage Settings (UPI, QR Code, Notices)
    Route::get('/settings', [AdminSettingController::class, 'index']);
    Route::post('/settings', [AdminSettingController::class, 'update']);

    // Manage Landing Page CMS
    Route::get('/landing-page', [AdminLandingPageController::class, 'index']);
    Route::post('/landing-page', [AdminLandingPageController::class, 'update']);
    Route::post('/landing-page/reset', [AdminLandingPageController::class, 'reset']);

    // Manage User Reviews & Testimonials
    Route::get('/reviews', [AdminReviewController::class, 'index']);
    Route::post('/reviews/{id}/toggle', [AdminReviewController::class, 'toggleStatus']);
    Route::delete('/reviews/{id}', [AdminReviewController::class, 'destroy']);
    Route::post('/reviews/{id}/block-user', [AdminReviewController::class, 'blockUser']);
    Route::post('/reviews/{id}/block-and-delete', [AdminReviewController::class, 'blockAndDelete']);
});
