<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserAdminService
{
    public function __construct(private Notifier $notifier)
    {
    }

    /**
     * Suspend an account. Effects:
     *   - the user is logged out everywhere at once (all their tokens are revoked) and cannot log in;
     *   - they lose seller access, so no new listings, shipping, replies or withdrawals;
     *   - their listings stop appearing in the catalog and cannot be bought (enforced where listings are shown and bought);
     *   - nobody can start or continue a chat with them.
     * Their money stays in their wallet, and orders already in motion settle through the normal timers,
     * so buyers stay protected. Admins cannot be suspended here, and nobody can suspend themselves.
     */
    public function suspend(User $target, User $admin, string $reason): User
    {
        $suspended = DB::transaction(function () use ($target, $admin, $reason) {
            $locked = User::whereKey($target->id)->lockForUpdate()->firstOrFail();

            if ($locked->id === $admin->id) {
                throw ValidationException::withMessages(['user' => 'You cannot suspend your own account.']);
            }

            if ($locked->isAdmin()) {
                throw ValidationException::withMessages(['user' => 'Admin accounts cannot be suspended here.']);
            }

            if (! $locked->isActive()) {
                throw ValidationException::withMessages(['user' => 'This account is already suspended.']);
            }

            // status is not fillable: only this service changes it.
            $locked->forceFill([
                'status' => UserStatus::Suspended,
                'suspended_at' => now(),
                'suspension_reason' => trim($reason),
            ])->save();

            $locked->tokens()->delete(); // out of every device, immediately

            return $locked;
        });

        $this->notifier->accountSuspended($suspended);

        return $suspended;
    }

    public function reactivate(User $target, User $admin): User
    {
        $active = DB::transaction(function () use ($target) {
            $locked = User::whereKey($target->id)->lockForUpdate()->firstOrFail();

            if ($locked->isActive()) {
                throw ValidationException::withMessages(['user' => 'This account is not suspended.']);
            }

            $locked->forceFill([
                'status' => UserStatus::Active,
                'suspended_at' => null,
                'suspension_reason' => null,
            ])->save();

            return $locked;
        });

        $this->notifier->accountReactivated($active);

        return $active;
    }
}