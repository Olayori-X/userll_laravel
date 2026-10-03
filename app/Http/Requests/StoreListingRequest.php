<?php

namespace App\Http\Requests;

use App\Enums\ListingCondition;
use App\Support\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware (auth, verified, can:sell) does the gatekeeping
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
            'title' => ['required', 'string', 'min:5', 'max:120'],
            'description' => ['required', 'string', 'min:20', 'max:5000'],
            'price' => ['required', self::priceRule()],
            'delivery_fee' => ['sometimes', 'nullable', self::deliveryFeeRule()],
            'condition' => ['required', Rule::enum(ListingCondition::class)],
            'stock' => ['required', 'integer', 'min:0', 'max:100000'],
            'city' => ['required', 'string', 'max:60'],
            'state' => ['required', 'string', 'max:60'],
            'images' => ['sometimes', 'array', 'max:'.config('marketplace.max_images_per_listing')],
            'images.*' => self::imageRules(),
        ];
    }

    /** Price is sent in naira ("100500", "100,500.50") and stored as kobo. */
    public static function priceRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $kobo = Money::tryFromNaira($value);

            if ($kobo === null) {
                $fail('The price must be a valid naira amount with at most 2 decimals.');
            } elseif ($kobo < config('marketplace.min_price_kobo')) {
                $fail('The price is below the minimum of '.Money::naira(config('marketplace.min_price_kobo')).'.');
            } elseif ($kobo > config('marketplace.max_price_kobo')) {
                $fail('The price is above the maximum of '.Money::naira(config('marketplace.max_price_kobo')).'.');
            }
        };
    }

    /** Delivery fee in naira. 0 (or leaving it out) means free delivery. */
    public static function deliveryFeeRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $kobo = Money::tryFromNaira($value);

            if ($kobo === null) {
                $fail('The delivery fee must be a valid naira amount with at most 2 decimals.');
            } elseif ($kobo > config('marketplace.max_delivery_fee_kobo')) {
                $fail('The delivery fee is above the maximum of '.Money::naira(config('marketplace.max_delivery_fee_kobo')).'.');
            }
        };
    }

    /** @return list<string> */
    public static function imageRules(): array
    {
        return ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=400,min_height=400'];
    }
}
