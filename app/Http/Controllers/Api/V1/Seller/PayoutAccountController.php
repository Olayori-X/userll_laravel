<?php

namespace App\Http\Controllers\Api\V1\Seller;

use App\Http\Controllers\Controller;
use App\Http\Resources\PayoutAccountResource;
use App\Services\PayoutAccountService;
use App\Services\PaystackException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayoutAccountController extends Controller
{
    /** Banks the seller can pick from. */
    public function banks(PayoutAccountService $accounts): JsonResponse
    {
        try {
            return response()->json(['data' => $accounts->banks()]);
        } catch (PaystackException) {
            return response()->json(['message' => 'The bank list is unavailable. Please try again in a moment.'], 502);
        }
    }

    /** The seller's current payout account, or null when none is set. */
    public function show(Request $request): PayoutAccountResource|JsonResponse
    {
        $account = $request->user()->payoutAccount;

        return $account ? new PayoutAccountResource($account) : response()->json(['data' => null]);
    }

    /** Add the payout account, or replace the existing one. The account name comes from the bank, not the seller. */
    public function save(Request $request, PayoutAccountService $accounts): PayoutAccountResource|JsonResponse
    {
        $data = $request->validate([
            'bank_code' => ['required', 'string', 'max:20'],
            'account_number' => ['required', 'string', 'regex:/^\d{10}$/'], // Nigerian bank account numbers are 10 digits
        ]);

        try {
            $account = $accounts->save($request->user(), $data['bank_code'], $data['account_number']);
        } catch (PaystackException) {
            return response()->json(['message' => 'We could not verify the bank account right now. Please try again in a moment.'], 502);
        }

        return new PayoutAccountResource($account);
    }
}