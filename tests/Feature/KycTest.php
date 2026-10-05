<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Models\KycSubmission;
use App\Models\PayoutAccount;
use App\Models\User;
use App\Services\KycService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesMarketplaceData;
use Tests\TestCase;

class KycTest extends TestCase
{
    use MakesMarketplaceData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['marketplace.kyc_disk' => 'local']);
    }

    private function photo(int $width = 600, int $height = 400): UploadedFile
    {
        return UploadedFile::fake()->image('id.jpg', $width, $height);
    }

        /** The seller sends documents through the real endpoint (multipart, because it carries a file). */
    private function submitAs(User $seller, array $overrides = [])
    {
        $response = $this->actingAs($seller, 'sanctum')->post('/api/v1/seller/kyc', $overrides + [
            'legal_name' => 'Ada Obi',
            'id_type' => 'nin_slip',
            'id_photo' => $this->photo(),
        ], ['Accept' => 'application/json']);

        // A test reuses one user object across requests. Drop its cached seller profile so the next
        // request reads the new status from the database, as a real request would.
        $seller->unsetRelation('sellerProfile');

        return $response;
    }

    /** A pending submission created through the service, with a real stored photo. */
    private function pendingSubmission(User $seller, string $legalName = 'Ada Obi'): KycSubmission
    {
        $submission = app(KycService::class)->submit($seller, $legalName, 'passport', $this->photo());

        $seller->unsetRelation('sellerProfile');

        return $submission;
    }

    /** A bare submission row, for tests that only look at names. */
    private function rawSubmission(User $seller, string $legalName): KycSubmission
    {
        return KycSubmission::create([
            'user_id' => $seller->id,
            'legal_name' => $legalName,
            'id_type' => 'passport',
            'id_photo_path' => 'kyc/none.jpg',
            'disk' => 'local',
            'status' => KycStatus::Pending,
        ]);
    }

    // ---------------------------------------------------------------- who can use it

    public function test_only_sellers_use_the_seller_side_and_only_admins_the_admin_side(): void
    {
        $this->getJson('/api/v1/seller/kyc')->assertUnauthorized();

        $buyer = User::factory()->create();
        $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/seller/kyc')->assertForbidden();

        $seller = $this->makeSeller();
        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/admin/kyc')->assertForbidden();
    }

    // ---------------------------------------------------------------- seller: status and submitting

    public function test_a_new_seller_sees_they_have_not_started(): void
    {
        $this->actingAs($this->makeSeller(), 'sanctum')->getJson('/api/v1/seller/kyc')
            ->assertOk()
            ->assertJsonPath('data.status', 'none')
            ->assertJsonPath('data.can_submit', true)
            ->assertJsonPath('data.latest', null)
            ->assertJsonCount(4, 'data.id_types');
    }

    public function test_seller_submits_documents_and_the_photo_is_stored_privately(): void
    {
        $seller = $this->makeSeller();

        $this->submitAs($seller, ['status' => 'verified', 'kyc_status' => 'verified']) // clients cannot choose the outcome
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.legal_name', 'Ada Obi')
            ->assertJsonMissingPath('data.id_photo_path')
            ->assertJsonMissingPath('data.disk');

        $submission = KycSubmission::firstOrFail();
        $this->assertSame(KycStatus::Pending, $submission->status);
        $this->assertSame('local', $submission->disk);
        $this->assertStringStartsWith('kyc/'.$seller->id.'/', $submission->id_photo_path);
        Storage::disk('local')->assertExists($submission->id_photo_path);

        $this->assertSame(KycStatus::Pending, $seller->fresh()->sellerProfile->kyc_status);

        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/seller/kyc')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.can_submit', false)
            ->assertJsonPath('data.latest.status', 'pending');
    }

    public function test_a_second_submission_while_one_is_waiting_is_refused_and_stores_nothing(): void
    {
        $seller = $this->makeSeller();
        $this->submitAs($seller)->assertCreated();

        $this->submitAs($seller)->assertUnprocessable()->assertJsonValidationErrors('kyc');

        $this->assertSame(1, KycSubmission::count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_bad_documents_are_refused_and_nothing_is_saved(): void
    {
        $seller = $this->makeSeller();

        $this->actingAs($seller, 'sanctum')->post('/api/v1/seller/kyc', [], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['legal_name', 'id_type', 'id_photo']);

        $this->submitAs($seller, ['id_type' => 'library_card'])->assertUnprocessable()->assertJsonValidationErrors('id_type');
        $this->submitAs($seller, ['id_photo' => $this->photo(100, 100)])->assertUnprocessable()->assertJsonValidationErrors('id_photo'); // too small to read
        $this->submitAs($seller, ['id_photo' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('id_photo');

        $this->assertSame(0, KycSubmission::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(KycStatus::None, $seller->fresh()->sellerProfile->kyc_status);
    }

    // ---------------------------------------------------------------- admin: queue, view, photo

    public function test_admin_sees_the_queue_and_one_submission_with_the_name_hint(): void
    {
        $admin = $this->makeAdmin();
        $seller = $this->makeSeller('Ada Stores');
        $submission = $this->pendingSubmission($seller);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/kyc')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.seller.store_name', 'Ada Stores')
            ->assertJsonPath('data.0.photo_path', "/api/v1/admin/kyc/{$submission->id}/photo")
            ->assertJsonMissingPath('data.0.name_match'); // only computed on the single view

        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/admin/kyc/{$submission->id}")
            ->assertOk()
            ->assertJsonPath('data.name_match', 'no_bank_account');
    }

    public function test_the_name_hint_compares_the_legal_name_with_the_bank_account_name(): void
    {
        $seller = $this->makeSeller();
        PayoutAccount::create([
            'user_id' => $seller->id,
            'bank_code' => '057',
            'bank_name' => 'Zenith Bank',
            'account_number' => '0123456789',
            'account_name' => 'ADA OBI',
        ]);

        $kyc = app(KycService::class);

        $this->assertSame('match', $kyc->nameMatch($this->rawSubmission($seller, 'Ada Obi')));
        $this->assertSame('match', $kyc->nameMatch($this->rawSubmission($seller, 'Obi Ada'))); // any order
        $this->assertSame('match', $kyc->nameMatch($this->rawSubmission($seller, 'Mrs Obi Ada Chinwe'))); // extra name, title ignored
        $this->assertSame('partial', $kyc->nameMatch($this->rawSubmission($seller, 'Ada Johnson')));
        $this->assertSame('mismatch', $kyc->nameMatch($this->rawSubmission($seller, 'Tunde Bakare')));
    }

    public function test_only_admins_can_see_the_id_photo_and_it_is_never_cached(): void
    {
        $admin = $this->makeAdmin();
        $seller = $this->makeSeller();
        $submission = $this->pendingSubmission($seller);

        $this->actingAs($seller, 'sanctum')->get("/api/v1/admin/kyc/{$submission->id}/photo")->assertForbidden();

        $response = $this->actingAs($admin, 'sanctum')->get("/api/v1/admin/kyc/{$submission->id}/photo")->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertNotEmpty($response->streamedContent());

        Storage::disk('local')->delete($submission->id_photo_path);
        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/admin/kyc/{$submission->id}/photo")->assertNotFound();
    }

    // ---------------------------------------------------------------- admin: approve and reject

    public function test_admin_approves_and_the_seller_becomes_verified(): void
    {
        $admin = $this->makeAdmin();
        $seller = $this->makeSeller();
        $submission = $this->pendingSubmission($seller);

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/kyc/{$submission->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'verified');

        $submission = $submission->fresh();
        $this->assertSame($admin->id, $submission->reviewed_by);
        $this->assertNotNull($submission->reviewed_at);

        $profile = $seller->fresh()->sellerProfile;
        $this->assertSame(KycStatus::Verified, $profile->kyc_status);
        $this->assertNotNull($profile->kyc_verified_at);
        $this->assertTrue($profile->isVerified());

        // Reviewing twice is refused, and a verified seller cannot send new documents.
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/kyc/{$submission->id}/approve")->assertUnprocessable();
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/kyc/{$submission->id}/reject", ['reason' => 'Changed my mind'])->assertUnprocessable();
        $this->submitAs($seller)->assertUnprocessable()->assertJsonValidationErrors('kyc');

        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/seller/kyc')
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.can_submit', false);
    }

    public function test_a_rejected_seller_sees_the_reason_and_can_submit_again(): void
    {
        $admin = $this->makeAdmin();
        $seller = $this->makeSeller();
        $first = $this->pendingSubmission($seller);

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/kyc/{$first->id}/reject", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/kyc/{$first->id}/reject", ['reason' => 'The photo is blurry, please retake it.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertSame(KycStatus::Rejected, $seller->fresh()->sellerProfile->kyc_status);
        $this->assertNull($seller->fresh()->sellerProfile->kyc_verified_at);

        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/seller/kyc')
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.can_submit', true)
            ->assertJsonPath('data.latest.rejection_reason', 'The photo is blurry, please retake it.');

        $this->submitAs($seller)->assertCreated();

        $this->assertSame(2, KycSubmission::count());
        $this->assertSame(KycStatus::Rejected, $first->fresh()->status); // the old attempt stays as history
        $this->assertSame(KycStatus::Pending, $seller->fresh()->sellerProfile->kyc_status);
    }

    // ---------------------------------------------------------------- which disk holds the photo

    public function test_each_submission_remembers_its_disk_so_old_photos_survive_a_switch(): void
    {
        $admin = $this->makeAdmin();
        $seller = $this->makeSeller();

        // Pretend Cloudflare is configured when the seller submits...
        Storage::fake('r2_test');
        config(['marketplace.kyc_disk' => 'r2_test']);

        $submission = $this->pendingSubmission($seller);

        $this->assertSame('r2_test', $submission->disk);
        Storage::disk('r2_test')->assertExists($submission->id_photo_path);
        Storage::disk('local')->assertMissing($submission->id_photo_path);

        // ...then the setting changes. The photo is still read from the disk it was saved to.
        config(['marketplace.kyc_disk' => 'local']);

        $this->actingAs($admin, 'sanctum')->get("/api/v1/admin/kyc/{$submission->id}/photo")->assertOk();
    }
}