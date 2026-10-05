<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Admin\AdminDisputeController;
use App\Http\Controllers\Api\V1\Admin\AdminPaymentController;
use App\Http\Controllers\Api\V1\Admin\AdminRefundController;
use App\Http\Controllers\Api\V1\AddressController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\ListingController;
use App\Http\Controllers\Api\V1\OrderActionController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaystackWebhookController;
use App\Http\Controllers\Api\V1\Seller\SellerListingController;
use App\Http\Controllers\Api\V1\Seller\SellerOrderActionController;
use App\Http\Controllers\Api\V1\Seller\SellerOrderController;
use App\Http\Controllers\Api\V1\Seller\SellerProfileController;
use App\Http\Controllers\Api\V1\SellerController;
use App\Http\Controllers\Api\V1\Seller\PayoutAccountController;
use App\Http\Controllers\Api\V1\Admin\AdminKycController;
use App\Http\Controllers\Api\V1\Seller\KycController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // ---------- Auth ----------
    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth');
        Route::post('forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:auth');
        Route::post('reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:auth');

        // Must keep this name: Laravel's verification email builds its link from it.
        Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
            ->middleware('signed')
            ->name('verification.verify');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
            Route::post('email/resend', [EmailVerificationController::class, 'resend'])->middleware('throttle:6,1');
        });
    });

    // ---------- Public catalog (no login) ----------
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('listings', [ListingController::class, 'index']);
    Route::get('listings/{listing:slug}', [ListingController::class, 'show']);
    Route::get('sellers/{sellerProfile:slug}', [SellerController::class, 'show']);

    // ---------- Payment provider callbacks (no login: protected by the Paystack signature) ----------
    Route::post('webhooks/paystack', [PaystackWebhookController::class, 'handle']);

    // ---------- Buying (login + verified email) ----------
    Route::middleware(['auth:sanctum', 'verified'])->group(function () {
        Route::get('cart', [CartController::class, 'show']);
        Route::delete('cart', [CartController::class, 'clear']);
        Route::post('cart/items', [CartController::class, 'addItem']);
        Route::patch('cart/items/{item}', [CartController::class, 'updateItem'])->whereNumber('item');
        Route::delete('cart/items/{item}', [CartController::class, 'removeItem'])->whereNumber('item');

        Route::get('addresses', [AddressController::class, 'index']);
        Route::post('addresses', [AddressController::class, 'store']);
        Route::patch('addresses/{address}', [AddressController::class, 'update'])->whereNumber('address');
        Route::delete('addresses/{address}', [AddressController::class, 'destroy'])->whereNumber('address');

        Route::post('checkout', [CheckoutController::class, 'store']);
        Route::get('checkouts/{reference}', [CheckoutController::class, 'show']);
        Route::post('checkouts/{reference}/pay', [PaymentController::class, 'pay'])->middleware('throttle:10,1');
        Route::post('checkouts/{reference}/verify', [PaymentController::class, 'verify'])->middleware('throttle:20,1');

        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{orderNumber}', [OrderController::class, 'show']);
        Route::post('orders/{orderNumber}/confirm', [OrderActionController::class, 'confirm']);
        Route::post('orders/{orderNumber}/cancel', [OrderActionController::class, 'cancel']);
        Route::post('orders/{orderNumber}/dispute', [OrderActionController::class, 'dispute']);
    });

    // ---------- Seller area (verified email required) ----------
    Route::middleware(['auth:sanctum', 'verified'])->prefix('seller')->group(function () {
        Route::post('profile', [SellerProfileController::class, 'store']); // become a seller

        Route::middleware('can:sell')->group(function () {
            Route::get('profile', [SellerProfileController::class, 'show']);
            Route::patch('profile', [SellerProfileController::class, 'update']);

            Route::get('banks', [PayoutAccountController::class, 'banks']);
            Route::get('payout-account', [PayoutAccountController::class, 'show']);
            Route::put('payout-account', [PayoutAccountController::class, 'save'])->middleware('throttle:10,1');

            Route::get('kyc', [KycController::class, 'show']);
            Route::post('kyc', [KycController::class, 'store'])->middleware('throttle:5,1');

            Route::get('listings', [SellerListingController::class, 'index']);
            Route::post('listings', [SellerListingController::class, 'store']);
            Route::get('listings/{listing}', [SellerListingController::class, 'show'])->whereNumber('listing');
            Route::patch('listings/{listing}', [SellerListingController::class, 'update'])->whereNumber('listing');
            Route::delete('listings/{listing}', [SellerListingController::class, 'destroy'])->whereNumber('listing');
            Route::post('listings/{listing}/publish', [SellerListingController::class, 'publish'])->whereNumber('listing');
            Route::post('listings/{listing}/unpublish', [SellerListingController::class, 'unpublish'])->whereNumber('listing');
            Route::post('listings/{listing}/images', [SellerListingController::class, 'addImages'])->whereNumber('listing');
            Route::delete('listings/{listing}/images/{image}', [SellerListingController::class, 'deleteImage'])
                ->whereNumber(['listing', 'image']);

            Route::get('orders', [SellerOrderController::class, 'index']);
            Route::get('orders/{orderNumber}', [SellerOrderController::class, 'show']);
            Route::post('orders/{orderNumber}/ship', [SellerOrderActionController::class, 'ship']);
            Route::post('orders/{orderNumber}/cancel', [SellerOrderActionController::class, 'cancel']);
        });
    });

    // ---------- Admin (login + verified email + admin role) ----------
    Route::middleware(['auth:sanctum', 'verified', 'can:admin'])->prefix('admin')->group(function () {
        Route::get('payments', [AdminPaymentController::class, 'index']);
        Route::post('payments/{payment}/refund', [AdminPaymentController::class, 'refund'])->whereNumber('payment');

        Route::get('refunds', [AdminRefundController::class, 'index']);
        Route::post('refunds/{refund}/retry', [AdminRefundController::class, 'retry'])->whereNumber('refund');

        Route::get('disputes', [AdminDisputeController::class, 'index']);
        Route::post('disputes/{dispute}/resolve', [AdminDisputeController::class, 'resolve'])->whereNumber('dispute');

        Route::get('kyc', [AdminKycController::class, 'index']);
        Route::get('kyc/{submission}', [AdminKycController::class, 'show'])->whereNumber('submission');
        Route::get('kyc/{submission}/photo', [AdminKycController::class, 'photo'])->whereNumber('submission');
        Route::post('kyc/{submission}/approve', [AdminKycController::class, 'approve'])->whereNumber('submission');
        Route::post('kyc/{submission}/reject', [AdminKycController::class, 'reject'])->whereNumber('submission');
    });

    // Step 5+ routes go here (payouts, KYC, reviews, chat). Use:
    //   ->middleware(['auth:sanctum', 'verified'])   for any action that needs a verified email
    //   ->middleware('can:admin')                     for admin-only actions
});
