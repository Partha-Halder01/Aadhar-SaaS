<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Razorpay API Credentials
    |--------------------------------------------------------------------------
    |
    | These credentials are used to communicate with the Razorpay API.
    | Obtain your Key ID and Key Secret from the Razorpay Dashboard.
    |
    */
    'key_id' => env('RAZORPAY_KEY_ID', ''),
    'key_secret' => env('RAZORPAY_KEY_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Razorpay Webhook Secret
    |--------------------------------------------------------------------------
    |
    | This secret is used to verify the authenticity of webhook requests
    | sent from Razorpay to your application.
    |
    */
    'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | Default currency for orders.
    |
    */
    'currency' => env('RAZORPAY_CURRENCY', 'INR'),
];
