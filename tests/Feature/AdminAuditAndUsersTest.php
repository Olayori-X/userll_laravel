<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Enums\ListingStatus;
use App\Enums\OrderStatus;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Listing;
use App\Models\User;
use App\Notifications\UserNotification;
use App\Services\KycService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\Concerns\MakesMarketplaceData;
use Tests\TestCase;

class AdminAuditAndUsersTest extends TestCase
{
    use MakesMarketplaceData, RefreshDatabase;

    private const REASON = 'Repeated fake listings reported by buyers.';

    // ---------------------------------------------------------------- helpers

    private function suspend(User $admin, User $target, string $reason = self::REASON, array $extra = [])
    {
        return $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/users/{$target->id}/suspend", ['reason' => $reason] + $extra);
    }

    private function reactivate(User $admin, User $target)
    {
        return $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/users/{$target->id}/reactivate");
    }

    private function countKind(User $user, string $kind): int
    {
        return Notification::sent($user, UserNotification::class)
            ->filter(fn (UserNotification $n) => $n->kind === $kind)
            ->count();
    }

    private function firstOfKind(User $user, string $kind): UserNotification
    {
        return Notification::sent($user, UserNotification::class)->first(fn (UserNotification $n) => $n->kind === $kind);
    }

    // ================================================================ the audit log

    public function test_every_admin_write_is_recorded_with_who_what_and_where(): void
    {
        $admin = $this->makeAdmin();
        $buyer = User::factory()->create();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeader('User-Agent', 'TestBrowser/1.0');

        $this->suspend($admin, $buyer, self::REASON, [
            'password' => 'hunter2',
            'extra' => ['token' => 'abc123', 'note' => 'plain'],
        ])->assertOk();

        $log = AuditLog::sole();

        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame('POST api/v1/admin/users/{user}/suspend', $log->action);
        $this->assertSame('POST', $log->method);
        $this->assertSame("api/v1/admin/users/{$buyer->id}/suspend", $log->path);
        $this->assertEquals((string) $buyer->id, (string) $log->route_params['user']);
        $this->assertSame(200, $log->status);
        $this->assertSame('203.0.113.9', $log->ip_address);
        $this->assertSame('TestBrowser/1.0', $log->user_agent);
        $this->assertNotNull($log->created_at);

        // What the admin sent is kept; secrets are never stored, at any depth.
        $this->assertSame(self::REASON, $log->input['reason']);
        $this->assertSame('plain', $log->input['extra']['note']);
        $this->assertArrayNotHasKey('password', $log->input);
        $this->assertSame('[hidden]', $log->input['extra']['token']);
        $this->assertStringNotContainsString('hunter2', json_encode($log->input));
        $this->assertStringNotContainsString('abc123', json_encode($log->input));
    }

    public function test_a_refused_admin_request_is_recorded_as_failed(): void
    {
        $admin = $this->makeAdmin();
        $buyer = User::factory()->create();

        $this->suspend($admin, $buyer, 'no')->assertUnprocessable(); // too short a reason

        $this->assertSame(422, AuditLog::sole()->status);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.0.status', 422)
            ->assertJsonPath('data.0.succeeded', false)
            ->assertJsonPath('data.0.input.reason', 'no')
            ->assertJsonPath('data.0.admin.email', $admin->email);
    }

    public function test_plain_reads_are_not_recorded_but_sensitive_ones_are(): void
    {
        $admin = $this->makeAdmin();
        $this->makeSeller();

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/users')->assertOk();
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/audit-logs')->assertOk(); // reading the log writes nothing
        $this->assertSame(0, AuditLog::count());

        // Looking at someone's ID photo is recorded.
        Storage::fake('local');
        config(['marketplace.kyc_disk' => 'local']);
        $seller = $this->makeSeller();
        $submission = app(KycService::class)->submit($seller, 'Ada Obi', 'passport', UploadedFile::fake()->image('id.jpg', 600, 400));

        $this->actingAs($admin, 'sanctum')->get("/api/v1/admin/kyc/{$submission->id}/photo")->assertOk();

        $log = AuditLog::sole();
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame('GET', $log->method);
        $this->assertSame('GET api/v1/admin/kyc/{submission}/photo', $log->action);
        $this->assertSame(200, $log->status);
    }

    public function test_people_who_are_not_admins_never_reach_the_log_and_leave_no_trace(): void
    {
        $seller = $this->makeSeller();
        $buyer = User::factory()->create();

        $this->postJson("/api/v1/admin/users/{$buyer->id}/suspend", ['reason' => self::REASON])->assertUnauthorized();
        $this->suspend($seller, $buyer)->assertForbidden();
        $this->suspend($buyer, $seller)->assertForbidden();
        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/admin/audit-logs')->assertForbidden();

        $this->assertSame(0, AuditLog::count());
        $this->assertSame(UserStatus::Active, $buyer->fresh()->status);
    }

    public function test_the_log_can_be_browsed_and_filtered(): void
    {
        $admin = $this->makeAdmin();
        $other = $this->makeAdmin();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $c = User::factory()->create();

        $this->suspend($admin, $a)->assertOk();
        $this->reactivate($admin, $a)->assertOk();
        $this->suspend($other, $b)->assertOk();
        $this->suspend($admin, $c, 'no')->assertUnprocessable();

        $url = '/api/v1/admin/audit-logs';
        $get = fn (string $query = '') => $this->actingAs($admin, 'sanctum')->getJson($url.$query);

        $all = $get()->assertOk()->assertJsonCount(4, 'data');
        $this->assertGreaterThan($all->json('data.3.id'), $all->json('data.0.id')); // newest first

        $get("?admin_id={$other->id}")->assertOk()->assertJsonCount(1, 'data');
        $get('?action=suspend')->assertOk()->assertJsonCount(3, 'data');
        $get('?action=reactivate')->assertOk()->assertJsonCount(1, 'data');
        $get('?failed=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.succeeded', false);
        $get('?method=POST')->assertOk()->assertJsonCount(4, 'data');
        $get('?method=GET')->assertOk()->assertJsonCount(0, 'data');
        $get("?path=users/{$a->id}/")->assertOk()->assertJsonCount(2, 'data'); // everything done to one account
        $get('?action=%25')->assertOk()->assertJsonCount(0, 'data');           // a typed % is not a wildcard

        $today = now()->toDateString();
        $get("?from={$today}&to={$today}")->assertOk()->assertJsonCount(4, 'data');
        $get('?from='.now()->addDay()->toDateString())->assertOk()->assertJsonCount(0, 'data');
        $get("?from={$today}&to=".now()->subDay()->toDateString())->assertUnprocessable();
        $get('?method=TRACE')->assertUnprocessable();

        // One entry in full, and it still reads fine after the admin's account is gone.
        $id = $all->json('data.2.id');
        $this->actingAs($admin, 'sanctum')->getJson("{$url}/{$id}")->assertOk()->assertJsonPath('data.id', $id);
        $this->actingAs($admin, 'sanctum')->getJson("{$url}/999999")->assertNotFound();

        $other->delete();
        $remaining = $this->actingAs($admin, 'sanctum')->getJson("{$url}?path=users/{$b->id}/")->assertOk();
        $this->assertNull($remaining->json('data.0.admin'));
    }

    public function test_log_entries_cannot_be_changed_or_deleted(): void
    {
        $admin = $this->makeAdmin();
        $this->suspend($admin, User::factory()->create())->assertOk();
        $log = AuditLog::sole();

        try {
            $log->update(['status' => 500]);
            $this->fail('An audit log entry was changed.');
        } catch (LogicException) {
            $this->assertSame(200, $log->fresh()->status);
        }

        try {
            $log->delete();
            $this->fail('An audit log entry was deleted.');
        } catch (LogicException) {
            $this->assertSame(1, AuditLog::count());
        }
    }

    public function test_the_log_uses_the_app_clock(): void
    {
        $admin = $this->makeAdmin();
        $buyer = User::factory()->create();

        $this->travelTo(Carbon::parse('2026-10-01 10:00:00'));
        $this->suspend($admin, $buyer)->assertOk();
        $this->travelBack();

        $this->assertSame('2026-10-01 10:00:00', AuditLog::sole()->created_at->toDateTimeString());
    }

    // ================================================================ the users list and detail

    public function test_the_user_list_searches_and_filters(): void
    {
        $admin = $this->makeAdmin();
        $seller = $this->makeSeller('Ada Stores');
        $buyer = User::factory()->create(['name' => 'Zed Quincy', 'email' => 'zed.q@example.com']);
        $gone = User::factory()->create();
        $gone->forceFill(['status' => UserStatus::Suspended])->save();

        $this->getJson('/api/v1/admin/users')->assertUnauthorized();
        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/admin/users')->assertForbidden();

        $get = fn (string $query = '') => $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/users'.$query);

        $all = $get()->assertOk()->assertJsonCount(4, 'data');
        $this->assertStringNotContainsString('$2y$', $all->getContent()); // no password hashes, ever

        $get('?type=admin')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'admin');
        $get('?type=seller')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.seller.store_name', 'Ada Stores');
        $get('?type=buyer')->assertOk()->assertJsonCount(2, 'data');
        $get('?status=suspended')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $gone->id);
        $get('?status=active')->assertOk()->assertJsonCount(3, 'data');
        $get('?search=zed.q')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $buyer->id);
        $get('?search=Quincy')->assertOk()->assertJsonCount(1, 'data');
        $get('?search=%25')->assertOk()->assertJsonCount(0, 'data'); // a typed % is not a wildcard
        $get('?kyc_status=none')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $seller->id);
        $get('?kyc_status=verified')->assertOk()->assertJsonCount(0, 'data');
        $get('?status=banished')->assertUnprocessable();
        $get('?kyc_status=nope')->assertUnprocessable();
    }

    public function test_the_user_detail_shows_stats_for_a_seller_and_for_a_buyer(): void
    {
        $admin = $this->makeAdmin();
        $seller = $this->makeSeller('Ada Stores');
        $buyer = User::factory()->create();

        Listing::factory()->create(['seller_id' => $seller->id]);
        Listing::factory()->create(['seller_id' => $seller->id, 'status' => ListingStatus::Draft]);
        $order = $this->makeEscrowedOrder($buyer, $seller, 10_000_000, ['status' => OrderStatus::Completed, 'released_at' => now()]);
        $order = $order->fresh();

        $seen = $this->actingAs($admin, 'sanctum')->getJson("/api/v1/admin/users/{$seller->id}")
            ->assertOk()
            ->assertJsonPath('data.type', 'seller')
            ->assertJsonPath('data.seller.store_name', 'Ada Stores')
            ->assertJsonPath('data.stats.as_seller.listings.active', 1)
            ->assertJsonPath('data.stats.as_seller.listings.draft', 1)
            ->assertJsonPath('data.stats.as_seller.listings.removed', 0)
            ->assertJsonPath('data.stats.as_seller.completed_orders', 1)
            ->assertJsonPath('data.stats.as_seller.sales_total', $order->total)
            ->assertJsonPath('data.stats.as_seller.commission_paid', $order->commission)
            ->assertJsonPath('data.stats.as_seller.disputes_against', 0)
            ->assertJsonPath('data.stats.as_seller.payouts.count', 0)
            ->assertJsonPath('data.stats.as_buyer.orders', 0);

        $this->assertIsInt($seen->json('data.stats.as_seller.wallet.available'));
        $this->assertIsInt($seen->json('data.stats.as_seller.wallet.pending'));

        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/admin/users/{$buyer->id}")
            ->assertOk()
            ->assertJsonPath('data.type', 'buyer')
            ->assertJsonPath('data.stats.as_buyer.orders', 1)
            ->assertJsonPath('data.stats.as_buyer.completed_orders', 1)
            ->assertJsonMissingPath('data.stats.as_seller');

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/users/999999')->assertNotFound();
    }

    // ================================================================ suspending and reactivating

    public function test_suspending_a_seller_switches_everything_off_and_reactivating_restores_it(): void
    {
        Notification::fake();
        $admin = $this->makeAdmin();
        $seller = $this->makeSeller('Ada Stores');
        $buyer = User::factory()->create();
        $listing = Listing::factory()->create(['seller_id' => $seller->id, 'stock' => 5]);
        $slug = $seller->sellerProfile->slug;

        // Before: everything is open.
        $this->getJson("/api/v1/listings/{$listing->slug}")->assertOk();
        $this->getJson('/api/v1/listings')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/sellers/{$slug}")->assertOk();
        $this->getJson("/api/v1/sellers/{$slug}/reviews")->assertOk();
        $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/cart/items', ['listing_id' => $listing->id, 'quantity' => 1])->assertOk();
        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/listings/{$listing->slug}/conversation")->assertCreated();
        $seller->createToken('web');
        $this->assertSame(1, $seller->tokens()->count());

        // The suspension.
        $this->suspend($admin, $seller)
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.suspension_reason', self::REASON);

        $suspended = $seller->fresh();
        $this->assertSame(UserStatus::Suspended, $suspended->status);
        $this->assertNotNull($suspended->suspended_at);
        $this->assertSame(0, $suspended->tokens()->count()); // logged out of every device

        // The person is told, by email, without the reason.
        $this->assertSame(1, $this->countKind($seller, 'account.suspended'));
        $this->assertTrue($this->firstOfKind($seller, 'account.suspended')->email);
        $this->assertStringNotContainsString('fake listings', $this->firstOfKind($seller, 'account.suspended')->body);

        // After: the account, its listings, its store and its chats are all closed.
        $this->postJson('/api/v1/auth/login', ['email' => $seller->email, 'password' => 'password'])->assertForbidden();
        $this->actingAs($suspended, 'sanctum')->getJson('/api/v1/seller/wallet')->assertForbidden();

        $this->getJson("/api/v1/listings/{$listing->slug}")->assertNotFound();
        $this->getJson('/api/v1/listings')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/sellers/{$slug}")->assertNotFound();
        $this->getJson("/api/v1/sellers/{$slug}/reviews")->assertNotFound();
        $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/cart/items', ['listing_id' => $listing->id, 'quantity' => 1])->assertClientError();
        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/listings/{$listing->slug}/conversation")
            ->assertUnprocessable()->assertJsonValidationErrors('listing');

        // And back again.
        $this->reactivate($admin, $seller)
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.suspension_reason', null);

        $this->assertNull($seller->fresh()->suspended_at);
        $this->assertSame(1, $this->countKind($seller, 'account.reactivated'));

        $this->getJson("/api/v1/listings/{$listing->slug}")->assertOk();
        $this->getJson('/api/v1/listings')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/sellers/{$slug}")->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => $seller->email, 'password' => 'password'])
            ->assertOk()->assertJsonStructure(['token']);
    }

    public function test_a_token_issued_before_a_suspension_stops_working_at_once(): void
    {
        $admin = $this->makeAdmin();
        $seller = $this->makeSeller();
        $token = $seller->createToken('web')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/seller/wallet')->assertOk();

        $this->suspend($admin, $seller)->assertOk();

        $this->app['auth']->forgetGuards(); // the next request must authenticate from scratch, not reuse the admin
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/seller/wallet')->assertUnauthorized();
    }

    public function test_the_rules_for_suspending_and_reactivating(): void
    {
        $admin = $this->makeAdmin();
        $otherAdmin = $this->makeAdmin();
        $seller = $this->makeSeller();
        $buyer = User::factory()->create();

        // Only admins.
        $this->suspend($seller, $buyer)->assertForbidden();

        // A reason that says something is required.
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/users/{$buyer->id}/suspend", [])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->suspend($admin, $buyer, 'no')->assertUnprocessable()->assertJsonValidationErrors('reason');

        // Not yourself, and not another admin.
        $this->suspend($admin, $admin)->assertUnprocessable()->assertJsonValidationErrors('user');
        $this->suspend($admin, $otherAdmin)->assertUnprocessable()->assertJsonValidationErrors('user');

        // Nobody was touched by any of that.
        $this->assertSame(UserStatus::Active, $buyer->fresh()->status);
        $this->assertSame(UserStatus::Active, $admin->fresh()->status);
        $this->assertSame(UserStatus::Active, $otherAdmin->fresh()->status);

        // Twice is refused, and so is reactivating someone who is not suspended.
        $this->suspend($admin, $buyer)->assertOk();
        $this->suspend($admin, $buyer)->assertUnprocessable()->assertJsonValidationErrors('user');
        $this->reactivate($admin, $seller)->assertUnprocessable()->assertJsonValidationErrors('user');

        // Unknown account.
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/users/999999/suspend', ['reason' => self::REASON])->assertNotFound();
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/users/999999/reactivate')->assertNotFound();
    }
}