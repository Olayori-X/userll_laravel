<?php

namespace App\Services;

use App\Enums\LedgerBucket;
use App\Enums\LedgerDirection;
use App\Enums\LedgerType;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * The only code allowed to change a wallet balance. Every change is one append-only ledger row,
 * written in the same database transaction, with the wallet row locked while it happens.
 *
 * Buckets: "pending" = money held in escrow, "available" = money that can be paid out.
 */
class LedgerService
{
    public function walletForSeller(int $userId): Wallet
    {
        return Wallet::firstOrCreate(['user_id' => $userId], ['type' => 'seller']);
    }

    public function platformWallet(): Wallet
    {
        try {
            return Wallet::firstOrCreate(
                ['singleton_key' => 'platform'],
                ['type' => 'platform'],
            );
        } catch (UniqueConstraintViolationException) {
            // Two requests raced to create it: the other one won, so read theirs.
            return Wallet::where('singleton_key', 'platform')->firstOrFail();
        }
    }

    /**
     * A paid order's money goes into escrow, split in two so nothing is lost or invented:
     * the seller's share and the platform's commission always add up to the order total.
     */
    public function holdEscrow(Order $order): void
    {
        $this->post(
            $this->walletForSeller($order->seller_id),
            LedgerType::EscrowHold, LedgerDirection::Credit, LedgerBucket::Pending,
            $order->sellerEarnings(), $order, null, ['part' => 'seller_share'],
        );

        if ($order->commission > 0) {
            $this->post(
                $this->platformWallet(),
                LedgerType::EscrowHold, LedgerDirection::Credit, LedgerBucket::Pending,
                $order->commission, $order, null, ['part' => 'commission'],
            );
        }
    }

    /**
     * Delivery done: the seller's share moves from "pending" (escrow) to "available" (can be paid out),
     * and the platform's commission becomes the platform's own money.
     */
    public function releaseEscrow(Order $order): void
    {
        $seller = $this->walletForSeller($order->seller_id);
        $earnings = $order->sellerEarnings();

        $this->post($seller, LedgerType::Release, LedgerDirection::Debit, LedgerBucket::Pending, $earnings, $order);
        $this->post($seller, LedgerType::Release, LedgerDirection::Credit, LedgerBucket::Available, $earnings, $order);

        if ($order->commission > 0) {
            $platform = $this->platformWallet();

            $this->post($platform, LedgerType::Commission, LedgerDirection::Debit, LedgerBucket::Pending, $order->commission, $order);
            $this->post($platform, LedgerType::Commission, LedgerDirection::Credit, LedgerBucket::Available, $order->commission, $order);
        }
    }

    /** The order is refunded before release: take its money back out of escrow. */
    public function reverseEscrow(Order $order): void
    {
        $this->post(
            $this->walletForSeller($order->seller_id),
            LedgerType::Refund, LedgerDirection::Debit, LedgerBucket::Pending,
            $order->sellerEarnings(), $order, null, ['part' => 'seller_share'],
        );

        if ($order->commission > 0) {
            $this->post(
                $this->platformWallet(),
                LedgerType::Refund, LedgerDirection::Debit, LedgerBucket::Pending,
                $order->commission, $order, null, ['part' => 'commission'],
            );
        }
    }

        /**
     * A seller asks to withdraw: the amount leaves their available balance at once, so the same
     * money cannot be withdrawn twice. It comes back only through returnPayout().
     */
    public function reservePayout(Payout $payout): LedgerEntry
    {
        return $this->post(
            $this->walletForSeller($payout->seller_id),
            LedgerType::Payout, LedgerDirection::Debit, LedgerBucket::Available,
            $payout->amount, null, $payout, ['part' => 'reserved'],
        );
    }

    /**
     * The payout failed or was reversed: the full amount goes back to the seller's available balance.
     * Safe to call twice for the same payout; the second call does nothing and returns null.
     */
    public function returnPayout(Payout $payout): ?LedgerEntry
    {
        return DB::transaction(function () use ($payout) {
            $wallet = Wallet::whereKey($this->walletForSeller($payout->seller_id)->id)->lockForUpdate()->firstOrFail();

            $alreadyReturned = LedgerEntry::where('payout_id', $payout->id)
                ->where('type', LedgerType::PayoutReturn->value)
                ->exists();

            if ($alreadyReturned) {
                return null;
            }

            return $this->post(
                $wallet,
                LedgerType::PayoutReturn, LedgerDirection::Credit, LedgerBucket::Available,
                $payout->amount, null, $payout, ['part' => 'returned', 'status' => $payout->status->value],
            );
        });
    }

    public function post(
        Wallet $wallet,
        LedgerType $type,
        LedgerDirection $direction,
        LedgerBucket $bucket,
        int $amount,
        ?Order $order = null,
        ?Payout $payout = null,
        array $meta = [],
    ): LedgerEntry {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Ledger amounts must be positive kobo.');
        }

        return DB::transaction(function () use ($wallet, $type, $direction, $bucket, $amount, $order, $payout, $meta) {
            $locked = Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();

            $column = $bucket === LedgerBucket::Pending ? 'pending_balance' : 'available_balance';
            $newBalance = $locked->{$column} + ($direction === LedgerDirection::Credit ? $amount : -$amount);

            if ($newBalance < 0) {
                throw new RuntimeException("Insufficient {$bucket->value} balance in wallet {$locked->id}.");
            }

            $locked->{$column} = $newBalance;
            $locked->save();

            return LedgerEntry::create([
                'wallet_id' => $locked->id,
                'order_id' => $order?->id,
                'payout_id' => $payout?->id,
                'type' => $type,
                'direction' => $direction,
                'bucket' => $bucket,
                'amount' => $amount,
                'balance_after' => $newBalance,
                'meta' => $meta ?: null,
            ]);
        });
    }
}
