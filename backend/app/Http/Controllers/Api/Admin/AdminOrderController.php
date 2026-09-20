<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
     * Approve or Reject Service Order (with automated wallet refund on rejection)
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
                'message' => "Order #{$order->order_number} approved! Moved to Processing.",
                'data' => $order->fresh(['user', 'service']),
            ]);
        }

        // Action === reject: handle inside DB transaction with automated wallet refund
        return DB::transaction(function () use ($order, $request) {
            $reason = trim($request->input('rejection_reason', ''));
            if (empty($reason)) {
                $reason = 'Application details could not be verified by administrator.';
            }

            $order->update([
                'payment_status' => 'rejected',
                'order_status' => 'rejected',
                'rejection_reason' => $reason,
            ]);

            $refunded = false;
            $refundAmount = 0;

            // If customer paid via wallet and amount > 0, credit the money back to user wallet
            if ($order->payment_method === 'wallet' && (float) $order->amount > 0) {
                // Ensure idempotency: check if refund transaction already exists for this order
                $alreadyRefunded = WalletTransaction::where('user_id', $order->user_id)
                    ->where('type', 'credit')
                    ->where('description', 'like', "%#{$order->order_number}%")
                    ->exists();

                if (!$alreadyRefunded) {
                    $user = User::lockForUpdate()->find($order->user_id);
                    if ($user) {
                        $user->wallet_balance = (float) $user->wallet_balance + (float) $order->amount;
                        $user->save();

                        WalletTransaction::create([
                            'user_id' => $user->id,
                            'type' => 'credit',
                            'amount' => $order->amount,
                            'balance_after' => $user->wallet_balance,
                            'description' => "Refund for Rejected Order #{$order->order_number} (Reason: {$reason})",
                            'status' => 'approved',
                        ]);

                        $refunded = true;
                        $refundAmount = (float) $order->amount;
                    }
                }
            }

            $message = $refunded
                ? "Order #{$order->order_number} rejected. ₹{$refundAmount} has been refunded to customer's wallet."
                : "Order #{$order->order_number} marked as rejected.";

            return response()->json([
                'status' => 'success',
                'message' => $message,
                'data' => $order->fresh(['user', 'service']),
                'refunded' => $refunded,
                'refund_amount' => $refundAmount,
            ]);
        });
    }

    /**
     * Fulfill order by uploading delivery PDF/card and admin notes (delivery_file is optional)
     */
    public function fulfill(Request $request, $id)
    {
        $order = ServiceOrder::findOrFail($id);

        $request->validate([
            'delivery_file' => 'nullable|file|mimes:jpeg,png,jpg,pdf|max:10240',
            'admin_notes' => 'nullable|string|max:1000',
        ]);

        $updateData = [
            'payment_status' => 'approved',
            'order_status' => 'completed',
        ];

        if ($request->hasFile('delivery_file')) {
            $updateData['delivery_file'] = $request->file('delivery_file')->store('deliveries', 'local');
        }

        if ($request->filled('admin_notes')) {
            $updateData['admin_notes'] = $request->admin_notes;
        }

        $order->update($updateData);

        return response()->json([
            'status' => 'success',
            'message' => $request->hasFile('delivery_file')
                ? 'Document uploaded & order fulfilled successfully! User can now download the file.'
                : 'Order marked as completed successfully!',
            'data' => $order,
        ]);
    }

    /**
     * Mark service order as completed
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
            'admin_notes' => $request->input('admin_notes', 'Service completed successfully.'),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Order marked as completed successfully!',
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

