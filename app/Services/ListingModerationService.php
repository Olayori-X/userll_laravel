<?php

namespace App\Services;

use App\Enums\ListingStatus;
use App\Models\Listing;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ListingModerationService
{
    public function __construct(private Notifier $notifier)
    {
    }

    /**
     * Take a listing off the market. It disappears from the catalog and cannot be bought or carted, and the
     * seller cannot publish it again until an admin restores it. Orders already placed are not affected.
     * Who did it is recorded in the audit log.
     */
    public function remove(Listing $listing, string $reason): Listing
    {
        $removed = DB::transaction(function () use ($listing, $reason) {
            $locked = Listing::whereKey($listing->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === ListingStatus::Removed) {
                throw ValidationException::withMessages(['listing' => 'This listing is already removed.']);
            }

            // status is not fillable: only this service changes it.
            $locked->status = ListingStatus::Removed;
            $locked->removed_at = now();
            $locked->removal_reason = trim($reason);
            $locked->save();

            return $locked;
        });

        $this->notifier->listingRemoved($removed);

        return $removed;
    }

    /** Bring a removed listing back as a draft. The seller reviews it and publishes it when ready. */
    public function restore(Listing $listing): Listing
    {
        $restored = DB::transaction(function () use ($listing) {
            $locked = Listing::whereKey($listing->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ListingStatus::Removed) {
                throw ValidationException::withMessages(['listing' => 'This listing is not removed.']);
            }

            $locked->status = ListingStatus::Draft;
            $locked->removed_at = null;
            $locked->removal_reason = null;
            $locked->save();

            return $locked;
        });

        $this->notifier->listingRestored($restored);

        return $restored;
    }
}