<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\KycStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminKycSubmissionResource;
use App\Models\KycSubmission;
use App\Services\KycService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminKycController extends Controller
{
    /** Submissions by status, by default the ones waiting for review (oldest first, so nobody waits forever). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['sometimes', Rule::enum(KycStatus::class)]]);

        $status = $request->input('status', KycStatus::Pending->value);

        return AdminKycSubmissionResource::collection(
            KycSubmission::where('status', $status)
                ->with('user.sellerProfile')
                ->orderBy('id', $status === KycStatus::Pending->value ? 'asc' : 'desc')
                ->paginate(30)
                ->withQueryString()
        );
    }

    /** One submission, with a hint on whether the legal name fits the seller's bank account name. */
    public function show(int $submission, KycService $kyc): AdminKycSubmissionResource
    {
        $submission = KycSubmission::with('user.sellerProfile')->findOrFail($submission);
        $submission->setAttribute('name_match', $kyc->nameMatch($submission));

        return new AdminKycSubmissionResource($submission);
    }

    /** The ID photo itself. Only admins reach this, and it is never cached. */
    public function photo(int $submission, KycService $kyc): StreamedResponse
    {
        return $kyc->photoResponse(KycSubmission::findOrFail($submission));
    }

    public function approve(Request $request, int $submission, KycService $kyc): AdminKycSubmissionResource
    {
        $approved = $kyc->approve(KycSubmission::findOrFail($submission), $request->user());

        return new AdminKycSubmissionResource($approved->load('user.sellerProfile'));
    }

    /** The reason is shown to the seller, so it must say what to fix. */
    public function reject(Request $request, int $submission, KycService $kyc): AdminKycSubmissionResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);

        $rejected = $kyc->reject(KycSubmission::findOrFail($submission), $request->user(), trim($data['reason']));

        return new AdminKycSubmissionResource($rejected->load('user.sellerProfile'));
    }
}