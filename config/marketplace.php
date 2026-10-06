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

    'payout_account_hold_hours' => (int) env('MARKETPLACE_PAYOUT_ACCOUNT_HOLD_HOURS', 24),

    // ---- Catalog ----
    // Filesystem disk for listing photos: 'public' locally, 's3' in production.
    'image_disk' => env('MARKETPLACE_IMAGE_DISK', 'public'),
    'max_images_per_listing' => 8,

    // PLACEHOLDER limits, in kobo. In .env write plain digits (no underscores).
    'min_price_kobo' => (int) env('MARKETPLACE_MIN_PRICE_KOBO', 10_000),
    'max_price_kobo' => (int) env('MARKETPLACE_MAX_PRICE_KOBO', 10_000_000_000),
    'max_delivery_fee_kobo' => (int) env('MARKETPLACE_MAX_DELIVERY_FEE_KOBO', 5_000_000), // ₦50,000

    // ---- KYC (seller identity documents) ----
    // ID photos are private. They go to Cloudflare R2 only when ALL four Cloudflare variables are
    // set in .env; otherwise they stay on the local private disk (storage/app/private).
    // Each submission remembers the disk it was saved to, so switching later never loses old photos.
    'kyc_disk' => (env('CLOUDFLARE_R2_ACCESS_KEY_ID')
        && env('CLOUDFLARE_R2_SECRET_ACCESS_KEY')
        && env('CLOUDFLARE_R2_BUCKET')
        && env('CLOUDFLARE_R2_ENDPOINT')) ? 'r2' : 'local',
    'kyc_photo_max_kb' => 5120, // 5 MB

    // ---- Payments ----
    'paystack' => [
        // Use your TEST secret key (sk_test_...) until you go live. Never commit it.
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        // Where Paystack sends the buyer after paying: FRONTEND_URL + this path + ?checkout=<reference>
        'callback_path' => '/checkout/complete',
    ],

        // ---- Payouts ----
    // PLACEHOLDER: the smallest withdrawal, in kobo (₦1,000). In .env write plain digits.
    'min_payout_kobo' => (int) env('MARKETPLACE_MIN_PAYOUT_KOBO', 100_000),

    // After a payout account is added or changed, payouts wait this many hours. 0 turns the hold off.
    'payout_account_hold_hours' => (int) env('MARKETPLACE_PAYOUT_ACCOUNT_HOLD_HOURS', 24),

    // The seller pays Paystack's transfer cost, taken out of the withdrawal. These are Paystack's
    // published Nigeria rates (checked October 2026); update them here if Paystack changes its pricing.
    'payout_fee' => [
        'tiers' => [
            ['up_to_kobo' => 500_000, 'fee_kobo' => 1_000],     // up to ₦5,000: ₦10
            ['up_to_kobo' => 5_000_000, 'fee_kobo' => 2_500],   // up to ₦50,000: ₦25
            ['up_to_kobo' => null, 'fee_kobo' => 5_000],        // above ₦50,000: ₦50
        ],
        'stamp_duty_kobo' => 5_000,            // ₦50 stamp duty...
        'stamp_duty_from_kobo' => 1_000_000,   // ...on transfers of ₦10,000 or more
    ],

    // A payout that Paystack has not confirmed is checked directly after this many minutes.
    'payout_reconcile_after_minutes' => 10,
];
