<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

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
        $request->validate([
            'action' => 'required|in:approve,reject',
            'rejection_reason' => 'nullable|string|max:500',
        ]);

        return DB::transaction(function () use ($id, $request) {
            $tx = WalletTransaction::lockForUpdate()->findOrFail($id);

            if ($tx->status !== 'pending') {
                return response()->json([
                    'status' => 'error',
                    'message' => "This wallet request is already {$tx->status}."
                ], 422);
            }

            if ($request->action === 'approve') {
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
        });
    }

    /**
     * Authenticated endpoint to securely view wallet transaction proof
     */
    public function proof(Request $request, $id)
    {
        return $this->serveProof(WalletTransaction::findOrFail($id));
    }

    /**
     * Issue a 5-minute signed link to a proof image (keeps the admin token out of URLs)
     */
    public function proofLink($id)
    {
        $tx = WalletTransaction::findOrFail($id);

        return response()->json([
            'status' => 'success',
            'url' => URL::temporarySignedRoute('files.wallet-proof', now()->addMinutes(5), ['id' => $tx->id], false),
        ]);
    }

    public function signedProof($id)
    {
        return $this->serveProof(WalletTransaction::findOrFail($id));
    }

    protected function serveProof(WalletTransaction $tx)
    {
        if (!$tx->proof_image) {
            return response()->json([
                'status' => 'error',
                'message' => 'No payment proof attached to this transaction.'
            ], 404);
        }

        $filePath = $tx->proof_image;
        $disk = 'local';
        if (!\Illuminate\Support\Facades\Storage::disk('local')->exists($filePath)) {
            if (\Illuminate\Support\Facades\Storage::disk('public')->exists($filePath)) {
                $disk = 'public';
            } else {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Proof image not found on server storage.'
                ], 404);
            }
        }

        $ext = pathinfo($filePath, PATHINFO_EXTENSION) ?: 'jpg';
        $fileName = "wallet_proof_{$tx->id}.{$ext}";

        return \Illuminate\Support\Facades\Storage::disk($disk)->response($filePath, $fileName);
    }
}

