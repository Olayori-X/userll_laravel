<?php

namespace App\Services;

use App\Enums\KycStatus;
use App\Models\KycSubmission;
use App\Models\PayoutAccount;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class KycService
{
    // ------------------------------------------------------------------ seller side

    /**
     * The seller sends their legal name and a photo of an ID. Only possible while no earlier
     * submission is waiting or approved. A rejected seller can simply submit again.
     */
    public function submit(User $seller, string $legalName, string $idType, UploadedFile $photo): KycSubmission
    {
        $this->assertCanSubmit($seller->sellerProfile);

        // Local private disk today, Cloudflare R2 once its variables are set. The disk is recorded
        // on the submission so the photo stays readable after a later switch.
        $disk = (string) config('marketplace.kyc_disk', 'local');
        $path = $photo->storeAs('kyc/'.$seller->id, Str::uuid()->toString().'.'.($photo->extension() ?: 'jpg'), $disk);

        if (! $path) {
            throw new RuntimeException('The ID photo could not be stored.');
        }

        try {
            return DB::transaction(function () use ($seller, $legalName, $idType, $disk, $path) {
                // Lock the profile so two quick submissions cannot both slip through.
                $profile = SellerProfile::whereKey($seller->sellerProfile->id)->lockForUpdate()->firstOrFail();
                $this->assertCanSubmit($profile);

                $submission = KycSubmission::create([
                    'user_id' => $seller->id,
                    'legal_name' => $legalName,
                    'id_type' => $idType,
                    'id_photo_path' => $path,
                    'disk' => $disk,
                    'status' => KycStatus::Pending,
                ]);

                // kyc_status is not fillable: only this service changes it.
                $profile->forceFill(['kyc_status' => KycStatus::Pending, 'kyc_verified_at' => null])->save();

                return $submission;
            });
        } catch (Throwable $e) {
            Storage::disk($disk)->delete($path); // no orphan photo if the submission was refused or failed
            throw $e;
        }
    }

    // ------------------------------------------------------------------ admin side

    public function approve(KycSubmission $submission, User $admin): KycSubmission
    {
        return DB::transaction(function () use ($submission, $admin) {
            [$profile, $locked] = $this->lockForReview($submission);

            $locked->update([
                'status' => KycStatus::Verified,
                'rejection_reason' => null,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            $profile->forceFill(['kyc_status' => KycStatus::Verified, 'kyc_verified_at' => now()])->save();

            return $locked;
        });
    }

    public function reject(KycSubmission $submission, User $admin, string $reason): KycSubmission
    {
        return DB::transaction(function () use ($submission, $admin, $reason) {
            [$profile, $locked] = $this->lockForReview($submission);

            $locked->update([
                'status' => KycStatus::Rejected,
                'rejection_reason' => $reason,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            $profile->forceFill(['kyc_status' => KycStatus::Rejected, 'kyc_verified_at' => null])->save();

            return $locked;
        });
    }

    /**
     * A hint for the reviewing admin, never a decision: does the legal name fit the name on the
     * seller's verified bank account? One of: match, partial, mismatch, no_bank_account.
     */
    public function nameMatch(KycSubmission $submission): string
    {
        $account = PayoutAccount::where('user_id', $submission->user_id)->first();

        if (! $account) {
            return 'no_bank_account';
        }

        $legal = $this->nameTokens($submission->legal_name);
        $bank = $this->nameTokens($account->account_name);

        if ($legal === [] || $bank === []) {
            return 'mismatch';
        }

        $common = array_intersect($legal, $bank);

        if (count($common) === min(count($legal), count($bank))) {
            return 'match'; // every word of the shorter name appears in the other, in any order
        }

        return $common !== [] ? 'partial' : 'mismatch';
    }

    /** The ID photo, streamed from whichever disk it was saved to. There is no public link to it. */
    public function photoResponse(KycSubmission $submission): StreamedResponse
    {
        $disk = Storage::disk($submission->disk);

        abort_unless($disk->exists($submission->id_photo_path), 404, 'The ID photo is missing.');

        return $disk->response($submission->id_photo_path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // ------------------------------------------------------------------ helpers

    private function assertCanSubmit(SellerProfile $profile): void
    {
        if ($profile->kyc_status === KycStatus::Pending) {
            throw ValidationException::withMessages(['kyc' => 'Your documents are already being reviewed.']);
        }

        if ($profile->kyc_status === KycStatus::Verified) {
            throw ValidationException::withMessages(['kyc' => 'Your identity is already verified.']);
        }
    }

    /**
     * Lock the profile, then the submission (always in that order), and make sure it still waits for review.
     *
     * @return array{0: SellerProfile, 1: KycSubmission}
     */
    private function lockForReview(KycSubmission $submission): array
    {
        $profile = SellerProfile::where('user_id', $submission->user_id)->lockForUpdate()->firstOrFail();
        $locked = KycSubmission::whereKey($submission->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== KycStatus::Pending) {
            throw ValidationException::withMessages(['kyc' => 'This submission has already been reviewed.']);
        }

        return [$profile, $locked];
    }

    /** @return list<string> the significant words of a name, upper-case, without titles or company suffixes */
    private function nameTokens(string $name): array
    {
        $words = preg_split('/[^A-Z]+/', Str::upper(Str::ascii($name)), -1, PREG_SPLIT_NO_EMPTY);

        $ignored = ['MR', 'MRS', 'MISS', 'MS', 'DR', 'PROF', 'ENGR', 'CHIEF', 'ALHAJI', 'ALHAJA', 'LTD', 'LIMITED'];

        return array_values(array_unique(array_filter(
            $words,
            fn (string $word) => strlen($word) > 1 && ! in_array($word, $ignored, true),
        )));
    }
}