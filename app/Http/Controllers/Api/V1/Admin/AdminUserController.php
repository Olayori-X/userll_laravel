<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\KycStatus;
use App\Enums\ListingStatus;
use App\Enums\OrderStatus;
use App\Enums\PayoutStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminUserResource;
use App\Models\Dispute;
use App\Models\Listing;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Review;
use App\Models\User;
use App\Models\Wallet;
use App\Services\UserAdminService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    /**
     * All accounts, newest first. Filters: ?search=ada (name or email), ?type=admin|seller|buyer,
     * ?status=active|suspended, ?kyc_status=pending (sellers only).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'type' => ['sometimes', Rule::in(['admin', 'seller', 'buyer'])],
            'status' => ['sometimes', Rule::enum(UserStatus::class)],
            'kyc_status' => ['sometimes', Rule::enum(KycStatus::class)],
        ]);

        $users = User::query()
            ->with('sellerProfile')
            ->when($data['search'] ?? null, function ($q, $text) {
                $like = '%'.addcslashes($text, '%_\\').'%'; // plain text: typed % and _ are not wildcards

                $q->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->when(($data['type'] ?? null) === 'admin', fn ($q) => $q->where('role', UserRole::Admin->value))
            ->when(($data['type'] ?? null) === 'seller', fn ($q) => $q->whereHas('sellerProfile'))
            ->when(($data['type'] ?? null) === 'buyer', fn ($q) => $q->where('role', UserRole::User->value)->whereDoesntHave('sellerProfile'))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['kyc_status'] ?? null, fn ($q, $kyc) => $q->whereHas('sellerProfile', fn ($q) => $q->where('kyc_status', $kyc)))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return AdminUserResource::collection($users);
    }

    public function show(int $user): AdminUserResource
    {
        $found = User::with('sellerProfile')->findOrFail($user);
        $found->setAttribute('stats', $this->stats($found));

        return new AdminUserResource($found);
    }

    /** Suspend an account: logged out everywhere, no selling, listings off the market. The reason is for admins only. */
    public function suspend(Request $request, int $user, UserAdminService $users): AdminUserResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);

        $suspended = $users->suspend(User::findOrFail($user), $request->user(), $data['reason']);

        return new AdminUserResource($suspended->load('sellerProfile'));
    }

    public function reactivate(Request $request, int $user, UserAdminService $users): AdminUserResource
    {
        $active = $users->reactivate(User::findOrFail($user), $request->user());

        return new AdminUserResource($active->load('sellerProfile'));
    }

    /** Numbers an admin wants before deciding anything about an account. All money is in kobo. */
    private function stats(User $user): array
    {
        $paidAsBuyer = fn () => Order::where('buyer_id', $user->id)->whereNotNull('paid_at');

        $stats = ['as_buyer' => [
            'orders' => $paidAsBuyer()->count(),
            'completed_orders' => $paidAsBuyer()->where('status', OrderStatus::Completed->value)->count(),
            'disputes_opened' => Dispute::where('opened_by', $user->id)->count(),
            'reviews_written' => Review::where('reviewer_id', $user->id)->count(),
        ]];

        if (! $user->sellerProfile) {
            return $stats;
        }

        $completed = fn () => Order::where('seller_id', $user->id)->where('status', OrderStatus::Completed->value);
        $wallet = Wallet::where('user_id', $user->id)->first();
        $payouts = fn () => Payout::where('seller_id', $user->id);

        $listingCounts = Listing::where('seller_id', $user->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $stats['as_seller'] = [
            'listings' => collect(ListingStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($listingCounts[$s->value] ?? 0)])->all(),
            'orders' => Order::where('seller_id', $user->id)->whereNotNull('paid_at')->count(),
            'completed_orders' => $completed()->count(),
            'sales_total' => (int) $completed()->sum('total'),
            'commission_paid' => (int) $completed()->sum('commission'),
            'disputes_against' => Dispute::whereHas('order', fn ($q) => $q->where('seller_id', $user->id))->count(),
            'wallet' => [
                'available' => (int) ($wallet?->available_balance ?? 0),
                'pending' => (int) ($wallet?->pending_balance ?? 0),
            ],
            'payouts' => [
                'count' => $payouts()->count(),
                'paid_total' => (int) $payouts()->where('status', PayoutStatus::Paid->value)->sum('amount'),
                'in_progress_total' => (int) $payouts()->whereIn('status', [PayoutStatus::Pending->value, PayoutStatus::Processing->value])->sum('amount'),
            ],
        ];

        return $stats;
    }
}