<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CartResource;
use App\Services\CartService;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(private CartService $carts)
    {
    }

    public function show(Request $request): CartResource
    {
        return $this->respond($request);
    }

    public function addItem(Request $request): CartResource
    {
        $data = $request->validate([
            'listing_id' => ['required', 'integer'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $this->carts->add($request->user(), $data['listing_id'], $data['quantity'] ?? 1);

        return $this->respond($request);
    }

    public function updateItem(Request $request, int $item): CartResource
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:100']]);

        $this->carts->setQuantity($this->carts->findItem($request->user(), $item), $data['quantity']);

        return $this->respond($request);
    }

    public function removeItem(Request $request, int $item): CartResource
    {
        $this->carts->findItem($request->user(), $item)->delete();

        return $this->respond($request);
    }

    public function clear(Request $request): CartResource
    {
        $request->user()->cart()->firstOrCreate()->items()->delete();

        return $this->respond($request);
    }

    private function respond(Request $request): CartResource
    {
        return new CartResource($this->carts->load($request->user()));
    }
}
