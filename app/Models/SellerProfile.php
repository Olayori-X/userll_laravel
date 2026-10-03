<?php

namespace App\Models;

use App\Enums\KycStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SellerProfile extends Model
{
    // kyc_status is set by the system/admin only, never from request input.
    protected $fillable = ['user_id', 'store_name', 'slug', 'bio', 'logo_path', 'state', 'city'];

    protected $attributes = ['kyc_status' => 'none'];

    protected function casts(): array
    {
        return ['kyc_status' => KycStatus::class, 'kyc_verified_at' => 'datetime'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function isVerified(): bool
    {
        return $this->kyc_status === KycStatus::Verified;
    }

    /** "Ada's Phones" => "adas-phones", then "adas-phones-2", "-3"... if taken. */
    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'store';
        $slug = $base;
        $i = 2;

        while (static::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
