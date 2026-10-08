<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Models\KycSubmission;
use App\Models\PayoutAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesMarketplaceData;
use Tests\TestCase;

/** A payout account must be in the same name as the seller's identity documents, whichever is saved first. */
class PayoutNameMatchTest extends TestCase
{
    use MakesMarketplaceData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Storage::fake('local');
        config(['marketplace.paystack.secret_key' => 'sk_test_secret', 'marketplace.kyc_disk' => 'local']);

        // Account 0123456789 belongs to "ADA OBI" at Zenith Bank (code 057).
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/bank/resolve')) {
                return Http::response(['status' => true, 'message' => 'ok', 'data' => ['account_number' => '0123456789', 'account_name' => 'ADA OBI']]);
            }

            if (str_contains($url, '/bank?')) {
                return Http::response(['status' => true, 'message' => 'ok', 'data' => [['name' => 'Zenith Bank', 'code' => '057', 'active' => true]], 'meta' => ['next' => null]]);
            }

            if (str_contains($url, '/transferrecipient')) {
                return Http::response(['status' => true, 'message' => 'ok', 'data' => ['recipient_code' => 'RCP_'.md5($request['account_number'])]], 201);
            }

            return Http::response([], 404);
        });
    }

    private function saveAccount(User $seller)
    {
        return $this->actingAs($seller, 'sanctum')
            ->putJson('/api/v1/seller/payout-account', ['bank_code' => '057', 'account_number' => '0123456789']);
    }

    private function submitKyc(User $seller, string $legalName)
    {
        $response = $this->actingAs($seller, 'sanctum')->post('/api/v1/seller/kyc', [
            'legal_name' => $legalName,
            'id_type' => 'nin_slip',
            'id_photo' => UploadedFile::fake()->image('id.jpg', 600, 400),
        ], ['Accept' => 'application/json']);

        $seller->unsetRelation('sellerProfile');

        return $response;
    }

    private function submission(User $seller, string $legalName, KycStatus $status): KycSubmission
    {
        return KycSubmission::create([
            'user_id' => $seller->id,
            'legal_name' => $legalName,
            'id_type' => 'passport',
            'id_photo_path' => 'kyc/none.jpg',
            'disk' => 'local',
            'status' => $status,
        ]);
    }

    // ---------------------------------------------------------------- saving the account second

    public function test_an_account_in_another_name_is_refused_and_nothing_is_saved(): void
    {
        $seller = $this->makeSeller();
        $this->submission($seller, 'Chidi Eze', KycStatus::Pending);

        $this->saveAccount($seller)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('account_number');

        $this->assertSame(0, PayoutAccount::count());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/transferrecipient')); // refused before registering the recipient
    }

    public function test_an_account_in_the_same_name_is_saved_in_any_word_order(): void
    {
        $seller = $this->makeSeller();
        $this->submission($seller, 'Obi Ada', KycStatus::Verified);

        $this->saveAccount($seller)->assertCreated();

        $this->assertSame(1, PayoutAccount::count());
    }

    public function test_a_partly_matching_name_is_allowed(): void
    {
        $seller = $this->makeSeller();
        $this->submission($seller, 'Ada Nwosu', KycStatus::Verified); // shares "ADA" with the account name

        $this->saveAccount($seller)->assertCreated();
    }

    public function test_a_seller_with_no_identity_submission_can_still_save_an_account(): void
    {
        $this->saveAccount($this->makeSeller())->assertCreated();
    }

    public function test_a_rejected_submission_does_not_count(): void
    {
        $seller = $this->makeSeller();
        $this->submission($seller, 'Chidi Eze', KycStatus::Rejected);

        $this->saveAccount($seller)->assertCreated();
    }

    public function test_only_the_newest_live_submission_counts(): void
    {
        $seller = $this->makeSeller();
        $this->submission($seller, 'Chidi Eze', KycStatus::Rejected);
        $this->submission($seller, 'Ada Obi', KycStatus::Pending);

        $this->saveAccount($seller)->assertCreated();
    }

    // ---------------------------------------------------------------- submitting identity second

    public function test_identity_documents_in_another_name_are_refused_before_the_photo_is_stored(): void
    {
        $seller = $this->makeSeller();
        $this->saveAccount($seller)->assertCreated();

        $this->submitKyc($seller, 'Chidi Eze')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('legal_name');

        $this->assertSame(0, KycSubmission::count());
        $this->assertSame([], Storage::disk('local')->allFiles()); // no orphan photo
        $this->assertSame(KycStatus::None, $seller->fresh()->sellerProfile->kyc_status);
    }

    public function test_matching_identity_documents_are_accepted_with_a_saved_account(): void
    {
        $seller = $this->makeSeller();
        $this->saveAccount($seller)->assertCreated();

        $this->submitKyc($seller, 'Ada Obi')->assertCreated();

        $this->assertSame(1, KycSubmission::count());
    }

    public function test_identity_documents_are_accepted_when_there_is_no_account_yet(): void
    {
        $this->submitKyc($this->makeSeller(), 'Chidi Eze')->assertCreated();
    }
}