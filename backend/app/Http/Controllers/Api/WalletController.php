<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
            'data' => $transactions,
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

        $cleanUtr = trim($request->utr_number);

        // Check for duplicate UTR in wallet transactions and direct UPI orders
        $duplicateTx = WalletTransaction::where('utr_number', $cleanUtr)
            ->where('status', '!=', 'rejected')
            ->exists();
        $duplicateOrder = \App\Models\ServiceOrder::where('utr_number', $cleanUtr)
            ->where('payment_status', '!=', 'rejected')
            ->exists();

        if ($duplicateTx || $duplicateOrder) {
            throw ValidationException::withMessages([
                'utr_number' => ['This UTR / Transaction Reference number has already been submitted or processed.']
            ]);
        }

        $proofPath = $request->file('payment_proof')->store('payments/wallet', 'public');

        $tx = WalletTransaction::create([
            'user_id' => $user->id,
            'type' => 'credit',
            'amount' => $request->amount,
            'balance_after' => $user->wallet_balance, // balance unchanged until approved
            'description' => 'Wallet Top-up (Pending Verification)',
            'proof_image' => $proofPath,
            'utr_number' => $cleanUtr,
            'status' => 'pending',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Wallet recharge request submitted successfully! Admin will verify and credit your balance.',
            'data' => $tx,
        ], 201);
    }
}
