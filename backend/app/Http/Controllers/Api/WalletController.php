<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    /**
     * Get user wallet balance and transaction ledger
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $transactions = WalletTransaction::where('user_id', $user->id)
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'wallet_balance' => (float) $user->wallet_balance,
            'transactions' => $transactions,
        ]);
    }

    /**
     * Submit wallet recharge request with UPI proof
     */
    public function recharge(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'amount' => 'required|numeric|min:10',
            'utr_number' => 'required|string|max:100',
            'payment_proof' => 'required|file|mimes:jpeg,png,jpg,pdf|max:5120',
        ]);

        $proofPath = $request->file('payment_proof')->store('payments/wallet', 'public');

        $tx = WalletTransaction::create([
            'user_id' => $user->id,
            'type' => 'credit',
            'amount' => $request->amount,
            'balance_after' => $user->wallet_balance, // balance unchanged until approved
            'description' => 'Wallet Top-up (Pending Verification)',
            'proof_image' => $proofPath,
            'utr_number' => $request->utr_number,
            'status' => 'pending',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Wallet recharge request submitted successfully! Admin will verify and credit your balance.',
            'data' => $tx,
        ], 201);
    }
}
