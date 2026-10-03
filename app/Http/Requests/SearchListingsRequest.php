<?php

namespace App\Http\Requests;

use App\Enums\ListingCondition;
use App\Support\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchListingsRequest extends FormRequest
{
    public const SORTS = ['newest', 'price_asc', 'price_desc'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $price = function (string $attribute, mixed $value, Closure $fail): void {
            if (Money::tryFromNaira($value) === null) {
                $fail("The {$attribute} must be a valid naira amount.");
            }
        };

        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category' => ['sometimes', 'string', Rule::exists('categories', 'slug')->where('is_active', true)],
            'seller' => ['sometimes', 'string', 'exists:seller_profiles,slug'],
            'condition' => ['sometimes', Rule::enum(ListingCondition::class)],
            'state' => ['sometimes', 'string', 'max:60'],
            'city' => ['sometimes', 'string', 'max:60'],
            'min_price' => ['sometimes', $price],
            'max_price' => ['sometimes', $price],
            'sort' => ['sometimes', Rule::in(self::SORTS)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
