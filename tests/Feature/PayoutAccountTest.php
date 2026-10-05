<?php

namespace Tests\Feature;

use App\Models\PayoutAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MakesMarketplaceData;
use Tests\TestCase;

class PayoutAccountTest extends TestCase
{
    use MakesMarketplaceData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['marketplace.paystack.secret_key' => 'sk_test_secret']);
        $this->fakePaystackBanking();
    }

    /**
     * 0123456789 resolves to ADA OBI, 0987654321 to ADA OBI LTD, 5555555555 makes Paystack fail,
     * anything else is "not found". Recipients are derived from the account number, so the same bank
     * account always gets the same recipient code, like the real thing.
     */
    private function fakePaystackBanking(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/bank/resolve')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $number = $query['account_number'] ?? null;

                return match ($number) {
                    '0123456789', '0987654321' => Http::response([
                        'status' => true,
                        'message' => 'Account number resolved',
                        'data' => ['account_number' => $number, 'account_name' => $number === '0123456789' ? 'ADA OBI' : 'ADA OBI LTD'],
                    ]),
                    '5555555555' => Http::response(['status' => false, 'message' => 'Server error'], 500),
                    default => Http::response(['status' => false, 'message' => 'Could not resolve account name.'], 422),
                };
            }

            if (str_contains($url, '/bank?')) {
                return Http::response([
                    'status' => true,
                    'message' => 'Banks retrieved',
                    'data' => [
                        ['name' => 'Zenith Bank', 'code' => '057', 'active' => true],
                        ['name' => 'Access Bank', 'code' => '044', 'active' => true],
                        ['name' => 'Closed Bank', 'code' => '999', 'active' => false],
                    ],
                    'meta' => ['next' => null],
                ]);
            }

            if (str_contains($url, '/transferrecipient')) {
                return Http::response([
                    'status' => true,
                    'message' => 'Transfer recipient created successfully',
                    'data' => [
                        'recipient_code' => 'RCP_'.md5($request['account_number']),
                        'details' => ['account_number' => $request['account_number'], 'bank_code' => $request['bank_code']],
                    ],
                ], 201);
            }

            return Http::response([], 404);
        });
    }

    private function save(User $seller, string $number = '0123456789', string $bank = '057', array $extra = [])
    {
        return $this->actingAs($seller, 'sanctum')
            ->putJson('/api/v1/seller/payout-account', ['bank_code' => $bank, 'account_number' => $number] + $extra);
    }

    // ---------------------------------------------------------------- who can use it

    public function test_guests_and_non_sellers_cannot_use_payout_accounts(): void
    {
        $this->getJson('/api/v1/seller/payout-account')->assertUnauthorized();

        $buyer = User::factory()->create();
        $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/seller/banks')->assertForbidden();
        $this->save($buyer)->assertForbidden();
    }

    // ---------------------------------------------------------------- bank list

    public function test_bank_list_is_sorted_hides_inactive_banks_and_is_cached(): void
    {
        $seller = $this->makeSeller();

        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/seller/banks')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Access Bank')
            ->assertJsonPath('data.1.code', '057');

        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/seller/banks')->assertOk();

        Http::assertSentCount(1); // the second request came from the cache
    }

    // ---------------------------------------------------------------- saving an account

    public function test_seller_with_no_account_sees_null(): void
    {
        $this->actingAs($this->makeSeller(), 'sanctum')->getJson('/api/v1/seller/payout-account')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_seller_saves_an_account_and_the_name_comes_from_the_bank(): void
    {
        $seller = $this->makeSeller();

        $response = $this->save($seller, extra: ['account_name' => 'Fake Name', 'verified_at' => null])
            ->assertCreated()
            ->assertJsonPath('data.account_name', 'ADA OBI') // not what the seller typed
            ->assertJsonPath('data.account_number', '******6789')
            ->assertJsonPath('data.bank_name', 'Zenith Bank')
            ->assertJsonPath('data.is_verified', true);

        // The full number and the Paystack recipient code never leave the server.
        $this->assertStringNotContainsString('0123456789', $response->getContent());
        $this->assertStringNotContainsString('RCP_', $response->getContent());

        $account = PayoutAccount::firstOrFail();
        $this->assertSame($seller->id, $account->user_id);
        $this->assertSame('RCP_'.md5('0123456789'), $account->paystack_recipient_code);
        $this->assertNotNull($account->verified_at);
    }

    public function test_a_new_account_holds_payouts_for_a_day(): void
    {
        $seller = $this->makeSeller();

        $this->save($seller)->assertCreated()->assertJsonPath('data.payouts_held_until', fn ($value) => $value !== null);

        $this->travel(25)->hours();

        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/seller/payout-account')
            ->assertOk()
            ->assertJsonPath('data.payouts_held_until', null);

        $this->travelBack();
    }

    public function test_replacing_the_account_updates_the_same_row_and_restarts_the_hold(): void
    {
        $seller = $this->makeSeller();
        $this->save($seller)->assertCreated();

        $this->travel(25)->hours();

        $this->save($seller, '0987654321', '044')
            ->assertOk()
            ->assertJsonPath('data.account_name', 'ADA OBI LTD')
            ->assertJsonPath('data.bank_name', 'Access Bank')
            ->assertJsonPath('data.payouts_held_until', fn ($value) => $value !== null);

        $this->travelBack();

        $this->assertSame(1, PayoutAccount::count());
        $this->assertSame('RCP_'.md5('0987654321'), PayoutAccount::firstOrFail()->paystack_recipient_code);
    }

    // ---------------------------------------------------------------- things that must be refused

    public function test_bad_input_and_unknown_accounts_are_refused_and_nothing_is_saved(): void
    {
        $seller = $this->makeSeller();

        $this->save($seller, '12345')->assertUnprocessable()->assertJsonValidationErrors('account_number'); // not 10 digits
        $this->save($seller, '0000000000')->assertUnprocessable()->assertJsonValidationErrors('account_number'); // bank says no such account
        $this->save($seller, '0123456789', '999')->assertUnprocessable()->assertJsonValidationErrors('bank_code'); // inactive bank
        $this->save($seller, '0123456789', '000')->assertUnprocessable()->assertJsonValidationErrors('bank_code'); // not a bank at all

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/transferrecipient'));
        $this->assertSame(0, PayoutAccount::count());
    }

    public function test_an_unknown_bank_is_rejected_before_asking_paystack_to_resolve(): void
    {
        $this->save($this->makeSeller(), '0123456789', '000')->assertUnprocessable();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/bank/resolve'));
    }

    public function test_paystack_being_down_gives_a_retry_message_and_saves_nothing(): void
    {
        $this->save($this->makeSeller(), '5555555555')->assertStatus(502);

        $this->assertSame(0, PayoutAccount::count());
    }

    public function test_one_bank_account_cannot_be_linked_to_two_sellers(): void
    {
        $this->save($this->makeSeller())->assertCreated();

        $this->save($this->makeSeller())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('account_number');

        $this->assertSame(1, PayoutAccount::count());
    }
}