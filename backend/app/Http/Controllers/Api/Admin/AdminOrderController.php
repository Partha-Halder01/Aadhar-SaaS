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

        $limit = min(max((int) $request->input('limit', 100), 1), 200);
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

        $filePath = $request->file('delivery_file')->store('deliveries', 'local');

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

    /**
     * Mark print service order as printed (customer collects physical card)
     */
    public function markPrinted(Request $request, $id)
    {
        $order = ServiceOrder::with('service')->findOrFail($id);

        if ($order->service && in_array($order->service->category, ['pan', 'pan_find'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'This action is not applicable for PAN Find Services.'
            ], 422);
        }

        $order->update([
            'payment_status' => 'approved',
            'order_status' => 'completed',
            'admin_notes' => $request->input('admin_notes', 'Printed successfully. Collect from our center.'),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Order marked as printed successfully! Customer notified to collect.',
            'data' => $order,
        ]);
    }

    /**
     * Send an alert request to customer for missing/deficient documents
     */
    public function requestDocument(Request $request, $id)
    {
        $order = ServiceOrder::with(['user', 'service'])->findOrFail($id);

        $request->validate([
            'doc_name' => 'required|string|max:255',
            'message' => 'required|string|max:1000',
        ]);

        $order->update([
            'doc_request_title' => $request->doc_name,
            'doc_request_message' => $request->message,
            'doc_request_status' => 'pending',
            'doc_request_requested_at' => now(),
            // Reset previous response if any re-requested
            'doc_response_file' => null,
            'doc_response_notes' => null,
            'doc_response_submitted_at' => null,
            'order_status' => $order->order_status === 'completed' ? $order->order_status : 'processing',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Missing document alert sent to customer successfully! Customer can now upload it directly.',
            'data' => $order->fresh(['user', 'service']),
        ]);
    }
}

