<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminWalletController extends Controller
{
    /**
     * List all wallet top-up requests
     */
    public function index()
    {
        $transactions = WalletTransaction::with('user')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $transactions,
        ]);
    }

    /**
     * Approve or reject wallet top-up request
     */
    public function process(Request $request, $id)
    {
        $tx = WalletTransaction::findOrFail($id);

        $request->validate([
            'action' => 'required|in:approve,reject',
            'rejection_reason' => 'nullable|string|max:500',
        ]);

        if ($tx->status !== 'pending') {
            return response()->json([
                'status' => 'error',
                'message' => "This wallet request is already {$tx->status}."
            ], 422);
        }

        if ($request->action === 'approve') {
            return DB::transaction(function () use ($tx, $request) {
                $user = User::lockForUpdate()->findOrFail($tx->user_id);
                $user->wallet_balance += $tx->amount;
                $user->save();

                $tx->update([
                    'status' => 'approved',
                    'balance_after' => $user->wallet_balance,
                    'approved_by' => $request->user()->id,
                    'description' => "Wallet Top-up of ₹{$tx->amount} (Approved)",
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => "Wallet top-up of ₹{$tx->amount} approved and credited to customer.",
                    'data' => $tx,
                ]);
            });
        }

        // Action === reject
        $tx->update([
            'status' => 'rejected',
            'rejection_reason' => $request->rejection_reason ?: 'Payment proof could not be verified.',
            'approved_by' => $request->user()->id,
            'description' => "Wallet Top-up Rejected (Reason: " . ($request->rejection_reason ?: 'Unverified') . ")",
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Wallet recharge request marked as rejected.',
            'data' => $tx,
        ]);
    }
}
