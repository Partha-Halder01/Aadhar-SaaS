<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AllApiService;
use App\Services\RazorpayService;
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
                // Login Greeting & Notice Popup
                'login_popup_enabled' => $all['login_popup_enabled'] ?? '1',
                'login_popup_badge' => $all['login_popup_badge'] ?? 'OFFICIAL NOTICE',
                'login_popup_welcome_title' => $all['login_popup_welcome_title'] ?? 'Welcome 🤗 Dear Valued Members',
                'login_popup_card_title' => $all['login_popup_card_title'] ?? 'IMPORTANT MESSAGE',
                'login_popup_card_message' => $all['login_popup_card_message'] ?? "All Services Working Fine 💯 🤝\nJoin Channel For Updates",
                'login_popup_btn1_text' => $all['login_popup_btn1_text'] ?? 'Got it ✅',
                'login_popup_btn2_text' => $all['login_popup_btn2_text'] ?? 'Join Channel',
                'login_popup_btn2_link' => $all['login_popup_btn2_link'] ?? 'https://whatsapp.com',
            ];
        });

        // Which online gateway the wallet page should use (read live, not cached, so .env changes apply at once)
        $settings['wallet_gateway'] = app(AllApiService::class)->isConfigured()
            ? 'allapi'
            : (app(RazorpayService::class)->isConfigured() ? 'razorpay' : null);

        return response()->json($settings);
    }
}
