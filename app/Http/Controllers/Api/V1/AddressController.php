<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AddressResource;
use App\Models\Address;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddressController extends Controller
{
    private const MAX_ADDRESSES = 10;

    public function index(Request $request): AnonymousResourceCollection
    {
        return AddressResource::collection(
            $request->user()->addresses()->orderByDesc('is_default')->latest('id')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->validated($request, creating: true);

        if ($user->addresses()->count() >= self::MAX_ADDRESSES) {
            throw ValidationException::withMessages(['address' => 'You can save up to '.self::MAX_ADDRESSES.' addresses.']);
        }

        $address = DB::transaction(function () use ($user, $data) {
            // The first address is always the default.
            $makeDefault = ! $user->addresses()->exists() || ($data['is_default'] ?? false);

            if ($makeDefault) {
                $user->addresses()->update(['is_default' => false]);
            }

            return $user->addresses()->create(array_merge($data, ['is_default' => $makeDefault]));
        });

        return (new AddressResource($address))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $address): AddressResource
    {
        $user = $request->user();
        $model = $user->addresses()->findOrFail($address);
        $data = $this->validated($request, creating: false);

        DB::transaction(function () use ($user, $model, $data) {
            // "is_default: false" on the current default is ignored: you switch default by choosing another address.
            if (($data['is_default'] ?? false) === true) {
                $user->addresses()->where('id', '!=', $model->id)->update(['is_default' => false]);
            } else {
                unset($data['is_default']);
            }

            $model->update($data);
        });

        return new AddressResource($model->fresh());
    }

    public function destroy(Request $request, int $address): JsonResponse
    {
        $user = $request->user();
        $model = $user->addresses()->findOrFail($address);
        $wasDefault = $model->is_default;

        DB::transaction(function () use ($user, $model, $wasDefault) {
            $model->delete();

            if ($wasDefault) {
                $user->addresses()->latest('id')->first()?->update(['is_default' => true]);
            }
        });

        return response()->json(null, 204);
    }

    private function validated(Request $request, bool $creating): array
    {
        if ($request->has('phone')) {
            $request->merge(['phone' => preg_replace('/[\s\-()]/', '', (string) $request->input('phone'))]);
        }

        $required = $creating ? ['required'] : ['sometimes', 'required'];

        $data = $request->validate([
            'label' => ['sometimes', 'nullable', 'string', 'max:50'],
            'recipient_name' => [...$required, 'string', 'max:100'],
            'phone' => [...$required, 'string', 'regex:/^\+?[0-9]{7,15}$/'],
            'street' => [...$required, 'string', 'max:200'],
            'city' => [...$required, 'string', 'max:60'],
            'state' => [...$required, 'string', 'max:60'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        // Form posts send "1"/"true" as text; make it a real boolean.
        if (array_key_exists('is_default', $data)) {
            $data['is_default'] = filter_var($data['is_default'], FILTER_VALIDATE_BOOLEAN);
        }

        return $data;
    }
}
