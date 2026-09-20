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
            // Login Greeting & Notice Popup
            'login_popup_enabled' => $all['login_popup_enabled'] ?? '1',
            'login_popup_badge' => $all['login_popup_badge'] ?? 'OFFICIAL NOTICE',
            'login_popup_welcome_title' => $all['login_popup_welcome_title'] ?? 'Welcome 🤗 Dear Valued Members',
            'login_popup_card_title' => $all['login_popup_card_title'] ?? 'IMPORTANT MESSAGE',
            'login_popup_card_message' => $all['login_popup_card_message'] ?? "All Services Working Fine 💯 🤝\nJoin Channel For Updates",
            'login_popup_btn1_text' => $all['login_popup_btn1_text'] ?? 'Got it ✅',
            'login_popup_btn2_text' => $all['login_popup_btn2_text'] ?? 'Join Channel',
            'login_popup_btn2_link' => $all['login_popup_btn2_link'] ?? 'https://whatsapp.com',
        ]);
    }

    /**
     * Update settings & custom QR Code image
     */
    public function update(Request $request)
    {
        $request->validate([
            'upi_id' => 'sometimes|required|string|max:255',
            'upi_name' => 'sometimes|required|string|max:255',
            'notice_board' => 'nullable|string',
            'qr_code' => 'nullable|file|mimes:jpeg,png,jpg,webp|max:5120',
            'login_popup_enabled' => 'nullable|string',
            'login_popup_badge' => 'nullable|string|max:255',
            'login_popup_welcome_title' => 'nullable|string|max:255',
            'login_popup_card_title' => 'nullable|string|max:255',
            'login_popup_card_message' => 'nullable|string|max:2000',
            'login_popup_btn1_text' => 'nullable|string|max:100',
            'login_popup_btn2_text' => 'nullable|string|max:100',
            'login_popup_btn2_link' => 'nullable|string|max:500',
        ]);

        if ($request->has('upi_id')) {
            Setting::set('upi_id', $request->upi_id);
        }
        if ($request->has('upi_name')) {
            Setting::set('upi_name', $request->upi_name);
        }
        if ($request->has('notice_board')) {
            Setting::set('notice_board', $request->notice_board ?? '');
        }

        if ($request->has('login_popup_enabled')) {
            Setting::set('login_popup_enabled', $request->login_popup_enabled == '1' || $request->login_popup_enabled == 'true' ? '1' : '0');
        }
        if ($request->has('login_popup_badge')) {
            Setting::set('login_popup_badge', $request->login_popup_badge);
        }
        if ($request->has('login_popup_welcome_title')) {
            Setting::set('login_popup_welcome_title', $request->login_popup_welcome_title);
        }
        if ($request->has('login_popup_card_title')) {
            Setting::set('login_popup_card_title', $request->login_popup_card_title);
        }
        if ($request->has('login_popup_card_message')) {
            Setting::set('login_popup_card_message', $request->login_popup_card_message);
        }
        if ($request->has('login_popup_btn1_text')) {
            Setting::set('login_popup_btn1_text', $request->login_popup_btn1_text);
        }
        if ($request->has('login_popup_btn2_text')) {
            Setting::set('login_popup_btn2_text', $request->login_popup_btn2_text);
        }
        if ($request->has('login_popup_btn2_link')) {
            Setting::set('login_popup_btn2_link', $request->login_popup_btn2_link);
        }

        if ($request->hasFile('qr_code')) {
            $path = $request->file('qr_code')->store('settings', 'public');
            Setting::set('qr_code', $path);
        }

        Cache::forget('public_portal_settings');

        return response()->json([
            'status' => 'success',
            'message' => 'Settings updated successfully.',
            'data' => $this->index()->original,
        ]);
    }
}
