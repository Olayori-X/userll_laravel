<?php

namespace App\Models;

use App\Enums\ListingCondition;
use App\Enums\ListingStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Listing extends Model
{
    use HasFactory, SoftDeletes;

    // seller_id and status are set by the server, never from request input.
    protected $fillable = [
        'category_id', 'title', 'slug', 'description', 'price', 'delivery_fee', 'delivery_fee',
        'condition', 'stock', 'city', 'state',
    ];

    protected $attributes = ['delivery_fee' => 0];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'delivery_fee' => 'integer',
            'delivery_fee' => 'integer',
            'stock' => 'integer',
            'condition' => ListingCondition::class,
            'status' => ListingStatus::class,
        ];
    }

    public function seller(): BelongsTo { return $this->belongsTo(User::class, 'seller_id'); }
    public function category(): BelongsTo { return $this->belongsTo(Category::class); }
    public function images(): HasMany { return $this->hasMany(ListingImage::class)->orderBy('position'); }

    /** Buyable right now: live and in stock. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ListingStatus::Active->value)->where('stock', '>', 0);
    }

    /** Keep active/sold_out in step with stock. Drafts and removed listings are left alone. */
    public function syncStockStatus(): void
    {
        if (in_array($this->status, [ListingStatus::Active, ListingStatus::SoldOut], true)) {
            $this->status = $this->stock > 0 ? ListingStatus::Active : ListingStatus::SoldOut;
        }
    }
}
