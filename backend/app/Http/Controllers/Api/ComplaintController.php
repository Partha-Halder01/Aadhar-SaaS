<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Models\ServiceOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ComplaintController extends Controller
{
    /**
     * List user support tickets
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $tickets = Complaint::with('serviceOrder')
            ->where('user_id', $user->id)
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $tickets,
        ]);
    }

    /**
     * Create new support ticket
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
            'order_number' => 'nullable|string|max:100',
        ]);

        $serviceOrderId = null;
        if ($request->order_number) {
            $order = ServiceOrder::where('order_number', $request->order_number)
                ->where('user_id', $user->id)
                ->first();
            if ($order) {
                $serviceOrderId = $order->id;
            }
        }

        $ticketNo = 'TKT-' . strtoupper(Str::random(7));

        $ticket = Complaint::create([
            'ticket_no' => $ticketNo,
            'user_id' => $user->id,
            'service_order_id' => $serviceOrderId,
            'order_number' => $request->order_number ? strip_tags(trim($request->order_number)) : null,
            'subject' => strip_tags(trim($request->subject)),
            'message' => strip_tags(trim($request->message)),
            'status' => 'open',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Support ticket submitted successfully! Admin will respond shortly.',
            'data' => $ticket,
        ], 201);
    }
}
