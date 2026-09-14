<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    protected string $driver;
    protected array $config;

    public function __construct()
    {
        $this->driver = config('services.sms.driver', 'local');
        $this->config = config('services.sms', []);
    }

    /**
     * Send OTP SMS to given phone number
     */
    public function sendOtp(string $phone, string $otp, string $purpose = 'register'): array
    {
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);

        // Standard message template
        $message = match ($purpose) {
            'login' => "Your Utkal Print Portal login OTP is {$otp}. Valid for 5 minutes. Do not share it.",
            'reset_password' => "Your Utkal Print Portal password reset code is {$otp}. Valid for 5 minutes.",
            default => "Your Utkal Print Portal registration code is {$otp}. Valid for 5 minutes. Do not share it.",
        };

        return match ($this->driver) {
            'apitxt' => $this->sendViaApiTxt($cleanPhone, $otp),
            'fast2sms' => $this->sendViaFast2Sms($cleanPhone, $otp, $message),
            'twilio' => $this->sendViaTwilio($cleanPhone, $message),
            default => $this->sendViaLocal($cleanPhone, $otp, $message, $purpose),
        };
    }

    /**
     * APITXT SMS OTP Gateway (https://apitxt.com/developer/otp-api)
     */
    protected function sendViaApiTxt(string $phone, string $otp): array
    {
        $authKey = $this->config['apitxt']['auth_key'] ?? null;

        if (!$authKey) {
            Log::warning("APITXT Auth Key missing. Falling back to local logging.");
            return $this->sendViaLocal($phone, $otp, "Your OTP is {$otp}", 'apitxt_fallback');
        }

        // Format phone: if 10 digits, prepend 91 for Indian mobile numbers
        $mobile = strlen($phone) === 10 ? "91{$phone}" : $phone;

        // Build query parameters
        $queryParams = [
            'authkey' => $authKey,
            'mobile'  => $mobile,
            'otp'     => $otp,
        ];

        if (!empty($this->config['apitxt']['channel'])) {
            $queryParams['channel'] = $this->config['apitxt']['channel'];
        }
        if (!empty($this->config['apitxt']['template_id'])) {
            $queryParams['template_id'] = $this->config['apitxt']['template_id'];
        }
        if (!empty($this->config['apitxt']['country'])) {
            $queryParams['country'] = $this->config['apitxt']['country'];
        }
        if (!empty($this->config['apitxt']['template_name'])) {
            $queryParams['template_name'] = $this->config['apitxt']['template_name'];
        }
        if (!empty($this->config['apitxt']['project_ref_id'])) {
            $queryParams['project_ref_id'] = $this->config['apitxt']['project_ref_id'];
        }

        $url = 'https://apitxt.com/api/sendOTP?' . http_build_query($queryParams);

        $verifySsl = (bool) ($this->config['verify_ssl'] ?? true);
        $caBundle = storage_path('cacert.pem');

        try {
            $curlOptions = [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
                CURLOPT_SSL_VERIFYPEER => $verifySsl,
                CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
            ];

            if ($verifySsl && file_exists($caBundle)) {
                $curlOptions[CURLOPT_CAINFO] = $caBundle;
            }

            $curl = curl_init();
            curl_setopt_array($curl, $curlOptions);
            $response = curl_exec($curl);
            $err = curl_error($curl);
            curl_close($curl);

            // No insecure retry: the auth key travels in this URL. Certificate errors on Windows are
            // fixed by keeping a CA bundle at storage/cacert.pem.

            if ($err) {
                Log::error("APITXT cURL Error: {$err}");
                return [
                    'success' => false,
                    'driver'  => 'apitxt',
                    'message' => 'SMS gateway connection failed. Please try again.',
                ];
            }

            $data = json_decode($response, true);

            if (($data['status'] ?? '') === 'success') {
                $requestId = $data['data']['request_id'] ?? '';
                Log::info("APITXT SMS sent successfully to {$mobile}. Request ID: {$requestId}");
                return [
                    'success'    => true,
                    'driver'     => 'apitxt',
                    'message'    => $data['message'] ?? 'SMS OTP sent successfully to mobile.',
                    'request_id' => $requestId,
                ];
            }

            Log::error("APITXT Gateway Error: {$response}");
            return [
                'success' => false,
                'driver'  => 'apitxt',
                'message' => $data['message'] ?? 'Failed to send SMS OTP via gateway.',
            ];
        } catch (\Throwable $e) {
            Log::error("APITXT Gateway Exception: " . $e->getMessage());
            return [
                'success' => false,
                'driver'  => 'apitxt',
                'message' => 'SMS gateway communication exception.',
            ];
        }
    }

    /**
     * Local / Log simulation driver (Development & Testing)
     */
    protected function sendViaLocal(string $phone, string $otp, string $message, string $purpose): array
    {
        Log::info("📨 [SMS SIMULATOR] To: {$phone} | OTP: [{$otp}] | Purpose: {$purpose} | Message: {$message}");

        return [
            'success' => true,
            'driver' => 'local',
            'message' => 'SMS dispatched (Development Simulator).',
            // In local/testing mode, return dev_otp for visual testing helper
            'dev_otp' => app()->environment('local', 'testing', 'dev') ? $otp : null,
        ];
    }

    /**
     * Get SSL options with custom CA bundle support
     */
    protected function getHttpOptions(): array
    {
        $caBundle = storage_path('cacert.pem');
        if (file_exists($caBundle)) {
            return ['verify' => $caBundle];
        }
        return ['verify' => (bool) ($this->config['verify_ssl'] ?? true)];
    }

    /**
     * Fast2SMS Gateway (India)
     */
    protected function sendViaFast2Sms(string $phone, string $otp, string $message): array
    {
        $apiKey = $this->config['fast2sms']['api_key'] ?? null;
        $route = $this->config['fast2sms']['route'] ?? 'otp';

        if (!$apiKey) {
            Log::warning("Fast2SMS API Key missing. Falling back to local logging.");
            return $this->sendViaLocal($phone, $otp, $message, 'fast2sms_fallback');
        }

        try {
            // Fast2SMS OTP Route
            if ($route === 'otp') {
                $response = Http::withOptions($this->getHttpOptions())->withHeaders([
                    'authorization' => $apiKey,
                ])->post('https://www.fast2sms.com/dev/bulkV2', [
                    'variables_values' => $otp,
                    'route' => 'otp',
                    'numbers' => substr($phone, -10),
                ]);
            } else {
                // Quick SMS Route
                $response = Http::withOptions($this->getHttpOptions())->withHeaders([
                    'authorization' => $apiKey,
                ])->post('https://www.fast2sms.com/dev/bulkV2', [
                    'route' => 'q',
                    'message' => $message,
                    'language' => 'english',
                    'flash' => 0,
                    'numbers' => substr($phone, -10),
                ]);
            }

            $data = $response->json();

            if ($response->successful() && ($data['return'] ?? false) === true) {
                Log::info("Fast2SMS sent successfully to {$phone}");
                return [
                    'success' => true,
                    'driver' => 'fast2sms',
                    'message' => 'SMS OTP sent successfully to mobile.',
                ];
            }

            Log::error("Fast2SMS Error: " . json_encode($data));
            return [
                'success' => false,
                'driver' => 'fast2sms',
                'message' => $data['message'][0] ?? 'Failed to deliver SMS via gateway.',
            ];
        } catch (\Throwable $e) {
            Log::error("Fast2SMS Exception: " . $e->getMessage());
            return [
                'success' => false,
                'driver' => 'fast2sms',
                'message' => 'SMS gateway communication failure.',
            ];
        }
    }

    /**
     * Twilio SMS Gateway (Global)
     */
    protected function sendViaTwilio(string $phone, string $message): array
    {
        $sid = $this->config['twilio']['sid'] ?? null;
        $token = $this->config['twilio']['token'] ?? null;
        $from = $this->config['twilio']['from'] ?? null;

        if (!$sid || !$token || !$from) {
            Log::warning("Twilio credentials missing. Falling back to local logging.");
            return $this->sendViaLocal($phone, '------', $message, 'twilio_fallback');
        }

        // Ensure E.164 phone format (+91 for India if 10 digits)
        $formattedPhone = strlen($phone) === 10 ? "+91{$phone}" : (str_starts_with($phone, '+') ? $phone : "+{$phone}");

        try {
            $response = Http::withOptions($this->getHttpOptions())
                ->withBasicAuth($sid, $token)
                ->asForm()
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                    'From' => $from,
                    'To' => $formattedPhone,
                    'Body' => $message,
                ]);

            if ($response->successful()) {
                Log::info("Twilio SMS sent successfully to {$formattedPhone}");
                return [
                    'success' => true,
                    'driver' => 'twilio',
                    'message' => 'SMS OTP delivered via Twilio.',
                ];
            }

            $err = $response->json();
            Log::error("Twilio Error: " . json_encode($err));
            return [
                'success' => false,
                'driver' => 'twilio',
                'message' => $err['message'] ?? 'Failed to send SMS via Twilio.',
            ];
        } catch (\Throwable $e) {
            Log::error("Twilio Exception: " . $e->getMessage());
            return [
                'success' => false,
                'driver' => 'twilio',
                'message' => 'Twilio network communication failure.',
            ];
        }
    }
}
