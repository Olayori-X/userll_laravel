<?php

return [
    // Where the Next.js app lives (used in verification, password-reset and payment-return links).
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    // PLACEHOLDER VALUES: decide your real numbers before launch.
    // Commission in basis points: 500 = 5%.
    'commission_bps' => (int) env('MARKETPLACE_COMMISSION_BPS', 500),

    // Seller must mark an order shipped within this many hours of payment,
    // otherwise it is cancelled and refunded.
    'ship_within_hours' => (int) env('MARKETPLACE_SHIP_WITHIN_HOURS', 72),

    // After "shipped", funds auto-release to the seller after this many days
    // unless the buyer confirms earlier or opens a dispute.
    'auto_release_days' => (int) env('MARKETPLACE_AUTO_RELEASE_DAYS', 7),

    // A checkout that stays unpaid this long is closed and its orders cancelled.
    'checkout_expiry_minutes' => (int) env('MARKETPLACE_CHECKOUT_EXPIRY_MINUTES', 60),

    // ---- Catalog ----
    // Filesystem disk for listing photos: 'public' locally, 's3' in production.
    'image_disk' => env('MARKETPLACE_IMAGE_DISK', 'public'),
    'max_images_per_listing' => 8,

    // PLACEHOLDER limits, in kobo. In .env write plain digits (no underscores).
    'min_price_kobo' => (int) env('MARKETPLACE_MIN_PRICE_KOBO', 10_000),
    'max_price_kobo' => (int) env('MARKETPLACE_MAX_PRICE_KOBO', 10_000_000_000),
    'max_delivery_fee_kobo' => (int) env('MARKETPLACE_MAX_DELIVERY_FEE_KOBO', 5_000_000), // ₦50,000

    // ---- Payments ----
    'paystack' => [
        // Use your TEST secret key (sk_test_...) until you go live. Never commit it.
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        // Where Paystack sends the buyer after paying: FRONTEND_URL + this path + ?checkout=<reference>
        'callback_path' => '/checkout/complete',
    ],
];
