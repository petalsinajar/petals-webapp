<?php

return [

    /*
    | Business rules for Petalsinajar. Money is always in minor units (pesewas).
    */

    'currency' => env('APP_CURRENCY', 'GHS'),

    // Where POST /v1/uploads stores images. `public` needs `php artisan storage:link`; use `s3` at scale.
    'uploads' => [
        'disk' => env('UPLOADS_DISK', 'public'),
    ],

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
        // Off for launch: customer and vendor settle payment between themselves. Bookings
        // are requests the vendor accepts (→ confirmed) or declines; nothing is charged.
        'in_app' => (bool) env('IN_APP_PAYMENTS', false),

        // fake | paystack
        'gateway' => env('PAYMENT_GATEWAY', 'fake'),
        'paystack' => [
            'secret_key' => env('PAYSTACK_SECRET_KEY'),
            'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
            'callback_url' => env('PAYSTACK_CALLBACK_URL'),
        ],
    ],

    'sms' => [
        // log | cbmessaging. Local and testing never send (see OtpService).
        'driver' => env('SMS_DRIVER', 'log'),

        'cbmessaging' => [
            'url' => env('CBMESSAGING_URL', 'https://cbmessaging.com/api/v1/'),
            'key' => env('SMS_KEY'),
            'secret' => env('SMS_SECRET'),
            // Must be registered with CB Messaging; at most 11 characters.
            'sender_id' => env('SMS_SENDER_ID'),
        ],
    ],

    // The `push` notification channel (app/Support/Push). Without a REST API
    // key, push is skipped and notifications only reach the in-app list.
    'push' => [
        'onesignal' => [
            'url' => env('ONESIGNAL_URL', 'https://api.onesignal.com/'),
            'app_id' => env('ONESIGNAL_APP_ID', '0a3e6f60-5b02-4338-8a89-edc6177108b6'),
            // Secret: OneSignal dashboard → Settings → Keys & IDs.
            'rest_api_key' => env('ONESIGNAL_REST_API_KEY'),
        ],
    ],

    // Vendor suggestions for hosts' upcoming events (app/Modules/Recommendations).
    'recommendations' => [
        'enabled' => (bool) env('RECOMMENDATIONS_ENABLED', true),
        'queue' => 'recommendations',
        // When the daily run starts, in business_timezone. Sends still wait for each event's daytime.
        'run_at' => '03:00',

        // What a typical event of each type rents, in priority order. "other" gets no suggestions.
        'required_categories' => [
            'wedding' => ['chairs', 'tables', 'tents', 'decor', 'lighting', 'sound'],
            'birthday' => ['chairs', 'tables', 'decor', 'kids'],
            'corporate' => ['chairs', 'tables', 'staging', 'sound'],
        ],

        // Days are counted until the event starts. Outside every stage nothing is sent.
        'stages' => [
            'checklist' => ['created_within_hours' => 48], // just after the event is created
            'missing_category' => ['from_days' => 56, 'to_days' => 14],
            'last_call' => ['from_days' => 11, 'to_days' => 7],
        ],

        'max_notifications_per_event_per_week' => 2,
        'max_vendors_per_notification' => 3,
        // Stop suggesting a category for an event after this many vendors, or this many dismissals.
        'max_vendors_per_category' => 6,
        'stop_category_after_dismissals' => 2,
        // No sends in this window, in the event's timezone. May wrap midnight.
        'quiet_hours' => ['start' => '21:00', 'end' => '08:00'],

        // Rough quantity per guest when estimating cost against the budget. Other categories count as 1 unit.
        'units_per_guest' => ['chairs' => 1, 'tables' => 0.1],
        // A vendor fits the budget if the estimated cost is at most this share of it.
        'max_budget_share' => 0.4,

        // Fairness: vendors recommended less often in this window get a boost.
        'fairness_window_days' => 30,
        'fairness_cap' => 20,
        // Score = weighted sum of components, each 0..1.
        'weights' => ['rating' => 0.25, 'experience' => 0.15, 'distance' => 0.2, 'price' => 0.15, 'fairness' => 0.25],

        // In-app list, and the phone through OneSignal (see 'push' above).
        'channels' => ['database', 'push'],
        'deep_link' => 'petalsinajar://events/{event}/suggestions',
        'category_labels' => [
            'chairs' => 'chair', 'tables' => 'table', 'tents' => 'tent', 'decor' => 'decor',
            'lighting' => 'lighting', 'sound' => 'sound', 'kids' => 'kids’ party', 'staging' => 'staging',
        ],
    ],

    // Public landing page and pre-launch waitlist.
    'marketing' => [
        'waitlist_per_minute' => 5,
        'launch_window' => env('LAUNCH_WINDOW', 'early 2027'),
        'launch_cities' => ['Accra', 'Kumasi', 'Takoradi', 'Tamale', 'Cape Coast'],
        'event_types' => ['wedding' => 'Weddings', 'birthday' => 'Birthdays', 'corporate' => 'Corporate events'],
        // Catalog category slugs a vendor can sign up under.
        'vendor_categories' => [
            'chairs' => 'Chairs & tables',
            'tents' => 'Tents & canopies',
            'decor' => 'Decor & florals',
            'lighting' => 'Lighting',
            'sound' => 'Sound & DJ',
            'catering' => 'Catering equipment',
            'kids' => 'Kids’ party',
            'staging' => 'Staging',
        ],
        'contact_email' => 'hello@petalsinajar.com',
        'instagram_handle' => 'petalsinajar',
        'socials' => [
            'Instagram' => 'https://instagram.com/petalsinajar',
            'TikTok' => 'https://www.tiktok.com/@petalsinajar',
            'X' => 'https://x.com/petalsinajar',
        ],
    ],
];
