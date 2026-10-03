<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    // role and status are deliberately NOT fillable (no privilege escalation via mass assignment).
    protected $fillable = ['name', 'email', 'phone', 'password'];

    protected $hidden = ['password', 'remember_token'];

    // In-memory defaults, so a freshly created user already has these set
    // (the database default alone is not loaded back into the model).
    protected $attributes = [
        'role' => 'user',
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
        ];
    }

    public function sellerProfile(): HasOne { return $this->hasOne(SellerProfile::class); }
    public function payoutAccount(): HasOne { return $this->hasOne(PayoutAccount::class); }
    public function wallet(): HasOne { return $this->hasOne(Wallet::class); }
    public function cart(): HasOne { return $this->hasOne(Cart::class); }
    public function addresses(): HasMany { return $this->hasMany(Address::class); }
    public function listings(): HasMany { return $this->hasMany(Listing::class, 'seller_id'); }
    public function purchases(): HasMany { return $this->hasMany(Order::class, 'buyer_id'); }
    public function sales(): HasMany { return $this->hasMany(Order::class, 'seller_id'); }

    public function isAdmin(): bool { return $this->role === UserRole::Admin; }
    public function isActive(): bool { return $this->status === UserStatus::Active; }
    public function isSeller(): bool { return $this->sellerProfile !== null; }
}
