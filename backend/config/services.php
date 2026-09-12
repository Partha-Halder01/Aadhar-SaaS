<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'sms' => [
        'driver' => env('SMS_DRIVER', 'apitxt'),
        'verify_ssl' => env('SMS_VERIFY_SSL', false),
        'apitxt' => [
            'auth_key' => env('APITXT_AUTH_KEY'),
            'channel' => env('APITXT_CHANNEL', ''),
            'template_id' => env('APITXT_TEMPLATE_ID', ''),
            'country' => env('APITXT_COUNTRY', '91'),
            'template_name' => env('APITXT_TEMPLATE_NAME', ''),
            'project_ref_id' => env('APITXT_PROJECT_REF_ID', ''),
        ],
        'fast2sms' => [
            'api_key' => env('FAST2SMS_API_KEY'),
            'route' => env('FAST2SMS_ROUTE', 'otp'),
        ],
        'twilio' => [
            'sid' => env('TWILIO_SID'),
            'token' => env('TWILIO_TOKEN'),
            'from' => env('TWILIO_FROM'),
        ],
    ],

];
