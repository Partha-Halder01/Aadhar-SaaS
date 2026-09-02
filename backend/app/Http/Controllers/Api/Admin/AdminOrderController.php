<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceOrder;
use Illuminate\Http\Request;

class AdminOrderController extends Controller
{
    /**
     * List all orders with filters
     */
    public function index(Request $request)
    {
        $query = ServiceOrder::with(['user', 'service']);

        if ($request->has('status') && !empty($request->status)) {
            $query->where('order_status', $request->status);
        }

        if ($request->has('payment_status') && !empty($request->payment_status)) {
            $query->where('payment_status', $request->payment_status);
        }

        $limit = $request->input('limit', 100);
        $orders = $query->orderBy('id', 'desc')->paginate($limit);

        return response()->json([
            'status' => 'success',
            'data' => $orders->items(),
            'total' => $orders->total(),
            'current_page' => $orders->currentPage(),
        ]);
    }

    /**
     * Show single order
     */
    public function show($id)
    {
        $order = ServiceOrder::with(['user', 'service'])->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $order,
        ]);
    }

    /**
     * Verify / Reject Order Payment
     */
    public function verifyPayment(Request $request, $id)
    {
        $order = ServiceOrder::findOrFail($id);

        $request->validate([
            'action' => 'required|in:approve,reject',
            'rejection_reason' => 'nullable|string|max:500',
        ]);

        if ($request->action === 'approve') {
            $order->update([
                'payment_status' => 'approved',
                'order_status' => 'processing',
                'rejection_reason' => null,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Payment approved! Order moved to processing.',
                'data' => $order,
            ]);
        }

        // Action === reject
        $order->update([
            'payment_status' => 'rejected',
            'order_status' => 'rejected',
            'rejection_reason' => $request->rejection_reason ?: 'Payment proof could not be verified.',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Order payment marked as rejected.',
            'data' => $order,
        ]);
    }

    /**
     * Fulfill order by uploading delivery PDF/card and admin notes
     */
    public function fulfill(Request $request, $id)
    {
        $order = ServiceOrder::findOrFail($id);

        $request->validate([
            'delivery_file' => 'required|file|mimes:jpeg,png,jpg,pdf|max:10240',
            'admin_notes' => 'nullable|string|max:500',
        ]);

        $filePath = $request->file('delivery_file')->store('deliveries', 'public');

        $order->update([
            'delivery_file' => $filePath,
            'admin_notes' => $request->admin_notes,
            'payment_status' => 'approved',
            'order_status' => 'completed',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Document uploaded & order fulfilled successfully! User can now download the file.',
            'data' => $order,
        ]);
    }
}
