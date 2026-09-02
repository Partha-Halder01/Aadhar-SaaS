<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Cache;

class AdminDashboardController extends Controller
{
    /**
     * Get admin dashboard KPIs with short caching (10s)
     */
    public function stats()
    {
        $stats = Cache::remember('admin_dashboard_stats', 10, function () {
            $totalRevenue = ServiceOrder::where('payment_status', 'approved')->sum('amount');
            $pendingOrders = ServiceOrder::where('order_status', 'pending')
                ->orWhere('payment_status', 'pending')
                ->count();
            $pendingWallet = WalletTransaction::where('status', 'pending')->count();
            $totalUsers = User::where('role', 'user')->count();
            $openTickets = Complaint::where('status', 'open')->count();
            $recentOrders = ServiceOrder::with(['user:id,name,phone,email', 'service:id,name,category'])
                ->orderBy('id', 'desc')
                ->limit(6)
                ->get();

            return [
                'total_revenue' => (float) $totalRevenue,
                'pending_orders' => $pendingOrders,
                'pending_wallet_requests' => $pendingWallet,
                'total_users' => $totalUsers,
                'open_tickets' => $openTickets,
                'recent_orders' => $recentOrders,
            ];
        });

        return response()->json($stats);
    }
}
