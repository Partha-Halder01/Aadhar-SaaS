<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class SettingController extends Controller
{
    /**
     * Get public settings for payment and notice with in-memory caching
     */
    public function publicSettings()
    {
        $settings = Cache::remember('public_portal_settings', 300, function () {
            $all = Setting::pluck('value', 'key')->toArray();
            return [
                'upi_id' => $all['upi_id'] ?? 'utkalprint@upi',
                'upi_name' => $all['upi_name'] ?? 'Utkal Print Portal',
                'qr_code' => $all['qr_code'] ?? null,
                'notice_board' => $all['notice_board'] ?? '',
            ];
        });

        return response()->json($settings);
    }
}
