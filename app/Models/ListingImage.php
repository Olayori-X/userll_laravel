<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ListingImage extends Model
{
    protected $fillable = ['listing_id', 'path', 'position'];

    public function listing(): BelongsTo { return $this->belongsTo(Listing::class); }

    protected function url(): Attribute
    {
        return Attribute::get(fn () => Storage::disk(config('marketplace.image_disk'))->url($this->path));
    }
}
