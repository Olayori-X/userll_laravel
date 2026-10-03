<?php

namespace App\Http\Controllers\Api\V1\Seller;

use App\Enums\ListingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreListingRequest;
use App\Http\Requests\UpdateListingRequest;
use App\Http\Resources\ListingCardResource;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use App\Services\ListingImageService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** A seller's own listings. Every lookup is scoped to the logged-in seller, so another seller's id gives a 404. */
class SellerListingController extends Controller
{
    public function __construct(private ListingImageService $images)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $request->user()->listings()->with('images')->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        return ListingCardResource::collection($query->paginate(24)->withQueryString());
    }

    public function store(StoreListingRequest $request): JsonResponse
    {
        $data = $request->validated();
        $files = $data['images'] ?? [];
        unset($data['images']);

        $data['price'] = Money::fromNaira($data['price']);
        if (array_key_exists('delivery_fee', $data)) {
            $data['delivery_fee'] = Money::fromNaira($data['delivery_fee'] ?? 0);
        }
        $data['slug'] = Str::slug($data['title']).'-'.Str::lower(Str::random(6));

        $listing = DB::transaction(function () use ($request, $data, $files) {
            $listing = new Listing($data);
            $listing->seller_id = $request->user()->id;
            $listing->status = ListingStatus::Draft; // sellers publish explicitly
            $listing->save();

            if ($files) {
                $this->images->attach($listing, $files);
            }

            return $listing;
        });

        return (new ListingResource($listing->load(['images', 'category'])))->response()->setStatusCode(201);
    }

    public function show(Request $request, int $listing): ListingResource
    {
        return new ListingResource($this->mine($request, $listing)->load(['images', 'category']));
    }

    public function update(UpdateListingRequest $request, int $listing): ListingResource
    {
        $model = $this->mine($request, $listing);
        $data = $request->validated();

        if (isset($data['price'])) {
            $data['price'] = Money::fromNaira($data['price']);
        }
        if (array_key_exists('delivery_fee', $data)) {
            $data['delivery_fee'] = Money::fromNaira($data['delivery_fee'] ?? 0);
        }

        $model->fill($data);
        $model->syncStockStatus(); // stock 0 => sold_out, stock refilled => active again
        $model->save();

        return new ListingResource($model->load(['images', 'category']));
    }

    /** Soft delete: the row stays so past orders keep their history. */
    public function destroy(Request $request, int $listing): JsonResponse
    {
        $this->mine($request, $listing)->delete();

        return response()->json(null, 204);
    }

    public function publish(Request $request, int $listing): ListingResource
    {
        $model = $this->mine($request, $listing);

        if ($model->status === ListingStatus::Removed) {
            throw ValidationException::withMessages(['status' => 'This listing was removed by an admin.']);
        }
        if ($this->images->count($model) < 1) {
            throw ValidationException::withMessages(['images' => 'Add at least one photo before publishing.']);
        }
        if ($model->stock < 1) {
            throw ValidationException::withMessages(['stock' => 'Set stock above zero before publishing.']);
        }

        $model->status = ListingStatus::Active;
        $model->save();

        return new ListingResource($model->load(['images', 'category']));
    }

    public function unpublish(Request $request, int $listing): ListingResource
    {
        $model = $this->mine($request, $listing);

        if ($model->status === ListingStatus::Removed) {
            throw ValidationException::withMessages(['status' => 'This listing was removed by an admin.']);
        }

        $model->status = ListingStatus::Draft;
        $model->save();

        return new ListingResource($model->load(['images', 'category']));
    }

    public function addImages(Request $request, int $listing): ListingResource
    {
        $model = $this->mine($request, $listing);
        $remaining = $this->images->remaining($model);

        if ($remaining < 1) {
            throw ValidationException::withMessages([
                'images' => 'This listing already has the maximum of '.config('marketplace.max_images_per_listing').' photos.',
            ]);
        }

        $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:'.$remaining],
            'images.*' => StoreListingRequest::imageRules(),
        ]);

        $this->images->attach($model, $request->file('images'));

        return new ListingResource($model->load(['images', 'category']));
    }

    public function deleteImage(Request $request, int $listing, int $image): ListingResource
    {
        $model = $this->mine($request, $listing);
        $img = $model->images()->whereKey($image)->firstOrFail();

        $isLive = in_array($model->status, [ListingStatus::Active, ListingStatus::SoldOut], true);
        if ($isLive && $this->images->count($model) <= 1) {
            throw ValidationException::withMessages(['images' => 'A live listing must keep at least one photo. Unpublish it first.']);
        }

        $this->images->delete($img);

        return new ListingResource($model->load(['images', 'category']));
    }

    private function mine(Request $request, int $id): Listing
    {
        return $request->user()->listings()->findOrFail($id);
    }
}
