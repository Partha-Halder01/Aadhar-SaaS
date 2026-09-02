<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AdminSettingController extends Controller
{
    /**
     * Get all admin portal settings
     */
    public function index()
    {
        $all = Setting::pluck('value', 'key')->toArray();
        return response()->json([
            'upi_id' => $all['upi_id'] ?? 'utkalprint@upi',
            'upi_name' => $all['upi_name'] ?? 'Utkal Print Portal',
            'qr_code' => $all['qr_code'] ?? null,
            'notice_board' => $all['notice_board'] ?? '',
        ]);
    }

    /**
     * Update settings & custom QR Code image
     */
    public function update(Request $request)
    {
        $request->validate([
            'upi_id' => 'required|string|max:255',
            'upi_name' => 'required|string|max:255',
            'notice_board' => 'nullable|string',
            'qr_code' => 'nullable|file|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        Setting::set('upi_id', $request->upi_id);
        Setting::set('upi_name', $request->upi_name);
        Setting::set('notice_board', $request->notice_board ?? '');

        if ($request->hasFile('qr_code')) {
            $path = $request->file('qr_code')->store('settings', 'public');
            Setting::set('qr_code', $path);
        }

        Cache::forget('public_portal_settings');

        return response()->json([
            'status' => 'success',
            'message' => 'Settings updated successfully.',
            'data' => [
                'upi_id' => Setting::get('upi_id'),
                'upi_name' => Setting::get('upi_name'),
                'qr_code' => Setting::get('qr_code'),
                'notice_board' => Setting::get('notice_board'),
            ],
        ]);
    }
}
