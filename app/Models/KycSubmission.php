<?php

namespace App\Models;

use App\Enums\KycStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycSubmission extends Model
{
    /** ID documents we accept. The key is what the API receives and stores. */
    public const ID_TYPES = [
        'nin_slip' => 'NIN slip',
        'passport' => 'International passport',
        'drivers_license' => "Driver's licence",
        'voters_card' => "Voter's card",
    ];

    // Server-created and server-reviewed only (KycService). Never fill from request input.
    protected $guarded = ['id'];

    // Where the ID photo lives is internal: it is never part of any API output.
    protected $hidden = ['id_photo_path', 'disk'];

    protected function casts(): array
    {
        return [
            'status' => KycStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', KycStatus::Pending->value);
    }

    public function idTypeLabel(): string
    {
        return self::ID_TYPES[$this->id_type] ?? $this->id_type;
    }
}