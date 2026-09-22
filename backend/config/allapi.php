<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AllAPI UPI Gateway
    |--------------------------------------------------------------------------
    |
    | AllAPI (allapi.in) creates a UPI payment page for each order. The
    | customer pays straight into the merchant UPI account linked in the
    | AllAPI dashboard, and AllAPI reports the payment status back to us.
    | Generate the API token from the AllAPI dashboard (Developers API).
    |
    */
    'token' => env('ALLAPI_TOKEN', ''),

    'base_url' => env('ALLAPI_BASE_URL', 'https://allapi.in'),

    /*
    | Where AllAPI sends the customer after paying. Defaults to the wallet
    | page served by this backend; set it to the public site's wallet page
    | in production (e.g. https://onlinedigitalservice.xyz/user/wallet.html).
    */
    'redirect_url' => env('ALLAPI_REDIRECT_URL', ''),

    /*
    | Secret added to the webhook URL (?key=...) so that only AllAPI can
    | trigger a status re-check. Webhook bodies are never trusted anyway:
    | every payment is re-confirmed with the AllAPI status API.
    */
    'webhook_key' => env('ALLAPI_WEBHOOK_KEY', ''),

    // AllAPI marks unpaid orders as failed after this many minutes
    'order_timeout_minutes' => (int) env('ALLAPI_ORDER_TIMEOUT_MINUTES', 30),

    'verify_ssl' => env('ALLAPI_VERIFY_SSL', true),
];
