<?php

return [
    'secret' => env('STRIPE_SECRET'),
    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    'currency' => env('STRIPE_CURRENCY', 'eur'),
    'connect_country' => env('STRIPE_CONNECT_COUNTRY', 'DE'),
    'frontend_url' => env('FRONTEND_APP_URL', 'http://localhost:5173'),
    'connect_return_url' => env('STRIPE_CONNECT_RETURN_URL'),
    'connect_refresh_url' => env('STRIPE_CONNECT_REFRESH_URL'),
    'checkout_success_url' => env('STRIPE_CHECKOUT_SUCCESS_URL'),
    'checkout_cancel_url' => env('STRIPE_CHECKOUT_CANCEL_URL'),
];
