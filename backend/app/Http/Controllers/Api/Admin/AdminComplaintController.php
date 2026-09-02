<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use Illuminate\Http\Request;

class AdminComplaintController extends Controller
{
    /**
     * List all complaints
     */
    public function index()
    {
        $complaints = Complaint::with(['user', 'serviceOrder'])
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $complaints,
        ]);
    }

    /**
     * Reply to complaint & update status
     */
    public function reply(Request $request, $id)
    {
        $complaint = Complaint::findOrFail($id);

        $request->validate([
            'reply' => 'required|string',
            'status' => 'required|in:open,in_progress,resolved',
        ]);

        $complaint->update([
            'admin_reply' => $request->reply,
            'status' => $request->status,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Reply sent & ticket updated successfully.',
            'data' => $complaint,
        ]);
    }
}
