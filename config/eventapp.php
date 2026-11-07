<?php

return [

    /*
    | Business rules for Petalsinajar. Money is always in minor units (pesewas).
    */

    'currency' => env('APP_CURRENCY', 'GHS'),

    // mongodb (production) | memory (tests, or running the API without MongoDB)
    'inventory_driver' => env('INVENTORY_DRIVER', 'mongodb'),

    // Timezone used to bucket rentals into calendar days for availability.
    'business_timezone' => env('BUSINESS_TIMEZONE', 'Africa/Accra'),

    'auth' => [
        'otp_length' => 6,
        'otp_ttl_minutes' => 10,
        'otp_max_attempts' => 5,
        // In local/testing the OTP is always this code and no SMS is sent.
        'otp_dev_code' => env('OTP_DEV_CODE', '123456'),
        'access_token_minutes' => (int) env('ACCESS_TOKEN_MINUTES', 60),
        'refresh_token_days' => (int) env('REFRESH_TOKEN_DAYS', 60),
    ],

    'bookings' => [
        // How long a customer's cart hold lasts for instant-book items.
        'hold_minutes' => 15,
        // How long a vendor has to accept a request before it expires.
        'vendor_response_hours' => 24,
        // After acceptance, how long the customer has to pay the deposit.
        'deposit_window_hours' => 24,
        // Payout is released this long after return if nobody raises an issue.
        'dispute_window_hours' => 48,
        'default_deposit_percent' => 30,
        'default_buffer_hours' => 12,
        'commission_percent' => (float) env('COMMISSION_PERCENT', 10),
        'service_fee_percent' => (float) env('SERVICE_FEE_PERCENT', 3),
    ],

    'feed' => [
        // Accounts with more followers than this skip fan-out on write.
        'fanout_follower_limit' => 10000,
        'feed_items_per_user' => 1000,
        'rescore_days' => 7,
    ],

    'payments' => [
        // fake | paystack
        'gateway' => env('PAYMENT_GATEWAY', 'fake'),
        'paystack' => [
            'secret_key' => env('PAYSTACK_SECRET_KEY'),
            'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
            'callback_url' => env('PAYSTACK_CALLBACK_URL'),
        ],
    ],

    'sms' => [
        // log | (add your provider, e.g. hubtel, arkesel)
        'driver' => env('SMS_DRIVER', 'log'),
    ],
];
