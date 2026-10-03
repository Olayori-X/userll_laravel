<?php

namespace App\Http\Requests;

/** Same rules as creating, but every field is optional. Images are managed on their own endpoints. */
class UpdateListingRequest extends StoreListingRequest
{
    public function rules(): array
    {
        return collect(parent::rules())
            ->except(['images', 'images.*'])
            ->map(fn (array $rule) => array_merge(['sometimes'], $rule))
            ->all();
    }
}
