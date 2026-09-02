<?php

use App\Http\Controllers\Api\Admin\AdminComplaintController;
use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\Admin\AdminOrderController;
use App\Http\Controllers\Api\Admin\AdminServiceController;
use App\Http\Controllers\Api\Admin\AdminSettingController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\AdminWalletController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ComplaintController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\OrderController;
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
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
});

// Public Services & Public Portal Settings
Route::get('/services', [ServiceController::class, 'index']);
Route::get('/services/{id}', [ServiceController::class, 'show']);
Route::get('/settings/public', [SettingController::class, 'publicSettings']);

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

    // Manage Wallet Requests
    Route::get('/wallet-requests', [AdminWalletController::class, 'index']);
    Route::post('/wallet-requests/{id}/process', [AdminWalletController::class, 'process']);

    // Manage Users
    Route::get('/users', [AdminUserController::class, 'index']);
    Route::post('/users/{id}/toggle-status', [AdminUserController::class, 'toggleStatus']);
    Route::post('/users/{id}/adjust-balance', [AdminUserController::class, 'adjustBalance']);

    // Manage Services
    Route::get('/services', [AdminServiceController::class, 'index']);
    Route::post('/services', [AdminServiceController::class, 'store']);
    Route::put('/services/{id}', [AdminServiceController::class, 'update']);
    Route::post('/services/{id}/toggle', [AdminServiceController::class, 'toggle']);

    // Manage Complaints
    Route::get('/complaints', [AdminComplaintController::class, 'index']);
    Route::post('/complaints/{id}/reply', [AdminComplaintController::class, 'reply']);

    // Manage Settings (UPI, QR Code, Notices)
    Route::get('/settings', [AdminSettingController::class, 'index']);
    Route::post('/settings', [AdminSettingController::class, 'update']);
});
