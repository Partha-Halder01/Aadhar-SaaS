<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    /**
     * List user orders with optional category/status filter
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = ServiceOrder::with('service')
            ->where('user_id', $user->id);

        if ($request->has('category') && !empty($request->category)) {
            $cat = $request->category;
            $query->whereHas('service', function ($q) use ($cat) {
                if ($cat === 'print' || $cat === 'print_doc') {
                    $q->whereNotIn('category', ['pan', 'pan_find']);
                } elseif ($cat === 'pan' || $cat === 'pan_find') {
                    $q->whereIn('category', ['pan', 'pan_find']);
                } else {
                    $q->where('category', $cat);
                }
            });
        }

        if ($request->has('status') && !empty($request->status)) {
            $query->where('order_status', $request->status);
        }

        $limit = $request->input('limit', 50);
        $orders = $query->orderBy('id', 'desc')->paginate($limit);

        return response()->json([
            'status' => 'success',
            'data' => $orders->items(),
            'total' => $orders->total(),
            'current_page' => $orders->currentPage(),
        ]);
    }

    /**
     * Create new service order (Wallet or Direct UPI)
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'service_id' => 'required|exists:services,id',
            'payment_method' => 'required|in:wallet,direct_upi',
            'utr_number' => 'nullable|string|max:100',
            'payment_proof' => 'nullable|file|mimes:jpeg,png,jpg,pdf|max:5120',
        ]);

        $service = Service::where('is_active', true)->findOrFail($request->service_id);
        $amount = $service->price;

        // Process input_data JSON & any dynamic file uploads
        $inputData = json_decode($request->input('input_data', '{}'), true) ?: [];

        // Check for dynamic uploaded files (e.g. file_aadhaar_file)
        foreach ($request->allFiles() as $key => $file) {
            if (str_starts_with($key, 'file_')) {
                $fieldName = substr($key, 5);
                $path = $file->store('orders/customer_inputs', 'public');
                $inputData[$fieldName] = $path;
            }
        }

        $orderNumber = 'UTK-' . date('Y') . '-' . strtoupper(Str::random(6));

        // Case 1: Payment via Wallet
        if ($request->payment_method === 'wallet') {
            if ($user->wallet_balance < $amount) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Insufficient wallet balance. Please recharge your wallet or choose Direct UPI payment.'
                ], 422);
            }

            return DB::transaction(function () use ($user, $service, $amount, $inputData, $orderNumber) {
                // Deduct wallet
                $user->wallet_balance -= $amount;
                $user->save();

                // Create Order
                $order = ServiceOrder::create([
                    'order_number' => $orderNumber,
                    'user_id' => $user->id,
                    'service_id' => $service->id,
                    'input_data' => $inputData,
                    'amount' => $amount,
                    'payment_method' => 'wallet',
                    'payment_status' => 'approved',
                    'order_status' => 'processing',
                ]);

                // Create Wallet Debit Ledger
                WalletTransaction::create([
                    'user_id' => $user->id,
                    'type' => 'debit',
                    'amount' => $amount,
                    'balance_after' => $user->wallet_balance,
                    'description' => "Payment for Order #{$orderNumber} ({$service->name})",
                    'status' => 'approved',
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Order placed successfully! Amount deducted from wallet.',
                    'data' => $order->load('service'),
                    'wallet_balance' => $user->wallet_balance,
                ], 201);
            });
        }

        // Case 2: Payment via Direct UPI
        $proofPath = null;
        if ($request->hasFile('payment_proof')) {
            $proofPath = $request->file('payment_proof')->store('payments/orders', 'public');
        }

        $order = ServiceOrder::create([
            'order_number' => $orderNumber,
            'user_id' => $user->id,
            'service_id' => $service->id,
            'input_data' => $inputData,
            'amount' => $amount,
            'payment_method' => 'direct_upi',
            'payment_status' => 'pending',
            'order_status' => 'pending',
            'utr_number' => $request->utr_number,
            'payment_proof_image' => $proofPath,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Order placed successfully! Admin will verify payment and process the request.',
            'data' => $order->load('service'),
        ], 201);
    }

    /**
     * Show single order detail
     */
    public function show(Request $request, $id)
    {
        $order = ServiceOrder::with('service')
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $order,
        ]);
    }
}
