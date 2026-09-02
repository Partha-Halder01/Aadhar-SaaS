<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceOrder;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    /**
     * Get user completed order documents
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $documents = ServiceOrder::with('service')
            ->where('user_id', $user->id)
            ->whereNotNull('delivery_file')
            ->where('order_status', 'completed')
            ->orderBy('updated_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $documents,
        ]);
    }
}
