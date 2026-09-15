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
            'data' => $transactions,
            'transactions' => $transactions,
        ]);
    }
}
