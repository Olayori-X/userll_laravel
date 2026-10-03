<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SellerProfileResource;
use App\Models\SellerProfile;

class SellerController extends Controller
{
    /** Public store page header. Their products come from GET /listings?seller={slug}. */
    public function show(SellerProfile $sellerProfile): SellerProfileResource
    {
        return new SellerProfileResource($sellerProfile);
    }
}
