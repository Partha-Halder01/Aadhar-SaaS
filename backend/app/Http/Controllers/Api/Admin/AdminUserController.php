<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminUserController extends Controller
{
    /**
     * List all registered customers
     */
    public function index(Request $request)
    {
        $query = User::where('role', '!=', 'admin');

        if ($request->has('search') && !empty($request->search)) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('email', 'like', "%{$s}%")
                  ->orWhere('phone', 'like', "%{$s}%");
            });
        }

        $users = $query->orderBy('id', 'desc')->get();

        return response()->json([
            'status' => 'success',
            'data' => $users,
        ]);
    }

    /**
     * Toggle user status (active / blocked)
     */
    public function toggleStatus($id)
    {
        $user = User::findOrFail($id);

        if ($user->role === 'admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Admin accounts cannot be blocked.'
            ], 422);
        }

        $user->status = $user->status === 'active' ? 'blocked' : 'active';
        $user->save();

        if ($user->status === 'blocked') {
            // Revoke active sessions immediately
            $user->tokens()->delete();
        }

        return response()->json([
            'status' => 'success',
            'message' => "User account has been {$user->status}.",
            'user' => $user,
        ]);
    }

    /**
     * Manual wallet balance adjustment
     */
    public function adjustBalance(Request $request, $id)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'type' => 'required|in:credit,debit',
            'description' => 'required|string|max:255',
        ]);

        return DB::transaction(function () use ($id, $request) {
            $user = User::lockForUpdate()->findOrFail($id);

            if ($user->role === 'admin') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Admin accounts cannot be modified.'
                ], 422);
            }

            $amount = (float) $request->amount;

            if ($request->type === 'debit' && $user->wallet_balance < $amount) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User does not have sufficient wallet balance to debit this amount.'
                ], 422);
            }

            if ($request->type === 'credit') {
                $user->wallet_balance += $amount;
            } else {
                $user->wallet_balance -= $amount;
            }
            $user->save();

            WalletTransaction::create([
                'user_id' => $user->id,
                'type' => $request->type,
                'amount' => $amount,
                'balance_after' => $user->wallet_balance,
                'description' => $request->description,
                'status' => 'approved',
                'approved_by' => $request->user()->id,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => "Wallet balance adjusted to ₹{$user->wallet_balance}.",
                'user' => $user,
            ]);
        });
    }
}
