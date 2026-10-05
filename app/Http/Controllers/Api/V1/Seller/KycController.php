<?php

namespace App\Http\Controllers\Api\V1\Seller;

use App\Enums\KycStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\KycSubmissionResource;
use App\Models\KycSubmission;
use App\Services\KycService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class KycController extends Controller
{
    /** Where the seller stands: status, whether they can (re)submit, and what they sent before. */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->sellerProfile;

        $history = KycSubmission::where('user_id', $user->id)->latest('id')->limit(10)->get();

        return response()->json(['data' => [
            'status' => $profile->kyc_status->value,
            'verified_at' => $profile->kyc_verified_at?->toIso8601String(),
            'can_submit' => in_array($profile->kyc_status, [KycStatus::None, KycStatus::Rejected], true),
            'id_types' => collect(KycSubmission::ID_TYPES)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'latest' => $history->isNotEmpty() ? KycSubmissionResource::make($history->first())->resolve() : null,
            'history' => KycSubmissionResource::collection($history)->resolve(),
        ]]);
    }

    /** Send the legal name and a photo of an ID for review. */
    public function store(Request $request, KycService $kyc): JsonResponse
    {
        $data = $request->validate([
            'legal_name' => ['required', 'string', 'min:3', 'max:120'],
            'id_type' => ['required', Rule::in(array_keys(KycSubmission::ID_TYPES))],
            'id_photo' => [
                'required', 'file', 'mimes:jpg,jpeg,png',
                'max:'.(int) config('marketplace.kyc_photo_max_kb', 5120),
                'dimensions:min_width=400,min_height=300', // a readable photo, not a thumbnail
            ],
        ]);

        try {
            $submission = $kyc->submit(
                $request->user(),
                trim($data['legal_name']),
                $data['id_type'],
                $request->file('id_photo'),
            );
        } catch (RuntimeException $e) {
            report($e); // storage trouble: logged for us, a retry message for the seller

            return response()->json(['message' => 'We could not save your document right now. Please try again in a moment.'], 503);
        }

        return KycSubmissionResource::make($submission)->response()->setStatusCode(201);
    }
}