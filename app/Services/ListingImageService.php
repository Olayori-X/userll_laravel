<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\ListingImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ListingImageService
{
    public function disk(): string
    {
        return config('marketplace.image_disk');
    }

    public function count(Listing $listing): int
    {
        return ListingImage::where('listing_id', $listing->id)->count();
    }

    public function remaining(Listing $listing): int
    {
        return max(0, config('marketplace.max_images_per_listing') - $this->count($listing));
    }

    /**
     * Store uploaded files with random names (so two sellers uploading "photo.jpg"
     * never overwrite each other) and add them after the existing images.
     *
     * @param  list<UploadedFile>  $files
     */
    public function attach(Listing $listing, array $files): void
    {
        $position = (int) ListingImage::where('listing_id', $listing->id)->max('position');
        $stored = [];

        try {
            foreach ($files as $file) {
                $path = $file->store("listings/{$listing->id}", $this->disk());
                $stored[] = $path;

                $listing->images()->create(['path' => $path, 'position' => ++$position]);
            }
        } catch (Throwable $e) {
            Storage::disk($this->disk())->delete($stored); // do not leave orphan files behind
            throw $e;
        }
    }

    public function delete(ListingImage $image): void
    {
        Storage::disk($this->disk())->delete($image->path);
        $image->delete();
    }
}
