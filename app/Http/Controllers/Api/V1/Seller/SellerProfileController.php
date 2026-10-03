<?php

namespace App\Http\Controllers\Api\V1\Seller;

use App\Http\Controllers\Controller;
use App\Http\Resources\SellerProfileResource;
use App\Models\SellerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SellerProfileController extends Controller
{
    /** Become a seller. Needs a verified email (route middleware). */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->sellerProfile()->exists()) {
            return response()->json(['message' => 'You already have a seller profile.'], 409);
        }

        $data = $request->validate([
            'store_name' => ['required', 'string', 'min:3', 'max:60', 'unique:seller_profiles,store_name'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'state' => ['required', 'string', 'max:60'],
            'city' => ['required', 'string', 'max:60'],
        ]);

        $profile = $user->sellerProfile()->create($data + ['slug' => SellerProfile::uniqueSlug($data['store_name'])]);

        return (new SellerProfileResource($profile))->response()->setStatusCode(201);
    }

    public function show(Request $request): SellerProfileResource
    {
        return new SellerProfileResource($request->user()->sellerProfile);
    }

    public function update(Request $request): SellerProfileResource
    {
        $profile = $request->user()->sellerProfile;

        $data = $request->validate([
            'store_name' => ['sometimes', 'string', 'min:3', 'max:60', Rule::unique('seller_profiles', 'store_name')->ignore($profile->id)],
            'bio' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'state' => ['sometimes', 'string', 'max:60'],
            'city' => ['sometimes', 'string', 'max:60'],
        ]);

        // The slug (store URL) deliberately does not change when the name does.
        $profile->update($data);

        return new SellerProfileResource($profile);
    }
}
