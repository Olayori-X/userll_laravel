<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HardeningTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------- token expiry

    public function test_login_tokens_expire_after_thirty_days_unless_configured_otherwise(): void
    {
        $this->assertSame(60 * 24 * 30, config('sanctum.expiration'));
    }

    public function test_a_token_stops_working_after_its_lifetime(): void
    {
        config(['sanctum.expiration' => 60]);
        $token = User::factory()->create()->createToken('web')->plainTextToken;
        $call = fn () => $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/notifications/unread-count');

        $call()->assertOk();

        $this->travel(59)->minutes();
        $this->app['auth']->forgetGuards();
        $call()->assertOk();

        $this->travel(2)->minutes(); // 61 minutes after it was made
        $this->app['auth']->forgetGuards();
        $call()->assertUnauthorized();

        $this->travelBack();
    }

    public function test_expiry_can_be_switched_off(): void
    {
        config(['sanctum.expiration' => null]);
        $token = User::factory()->create()->createToken('web')->plainTextToken;

        $this->travel(400)->days();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/notifications/unread-count')->assertOk();

        $this->travelBack();
    }

    // ---------------------------------------------------------------- CORS

    private function preflight(string $origin)
    {
        return $this->call('OPTIONS', '/api/v1/listings', [], [], [], [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);
    }

    public function test_browsers_from_an_allowed_website_may_call_the_api(): void
    {
        config(['cors.allowed_origins' => ['https://app.userll.test']]);

        $preflight = $this->preflight('https://app.userll.test');
        $this->assertSame('https://app.userll.test', $preflight->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotNull($preflight->headers->get('Access-Control-Allow-Headers'));

        $this->withHeader('Origin', 'https://app.userll.test')->getJson('/api/v1/listings')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://app.userll.test');
    }

    public function test_other_websites_get_no_permission(): void
    {
        // With a single allowed website the server always answers with that website's address, and the browser
        // refuses any page whose own address is different. What matters is that the answer is never the other
        // site's address and never "*".
        config(['cors.allowed_origins' => ['https://app.userll.test']]);

        $responses = [
            $this->preflight('https://evil.example'),
            $this->withHeader('Origin', 'https://evil.example')->getJson('/api/v1/listings'),
        ];

        foreach ($responses as $response) {
            $allowed = $response->headers->get('Access-Control-Allow-Origin');

            $this->assertNotSame('https://evil.example', $allowed);
            $this->assertNotSame('*', $allowed);
        }

        // With several allowed websites, one that is not on the list gets no header at all,
        // and each listed one is answered with its own address.
        config(['cors.allowed_origins' => ['https://app.userll.test', 'https://www.userll.test']]);

        $this->assertNull($this->preflight('https://evil.example')->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('https://www.userll.test', $this->preflight('https://www.userll.test')->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_with_no_allowed_websites_nobody_is_allowed(): void
    {
        config(['cors.allowed_origins' => []]);

        $this->assertNull($this->preflight('https://app.userll.test')->headers->get('Access-Control-Allow-Origin'));
    }

    // ---------------------------------------------------------------- .env.example

    public function test_env_example_documents_what_the_app_needs_and_holds_no_secrets(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        foreach ([
            'APP_KEY', 'APP_URL', 'FRONTEND_URL', 'CORS_ALLOWED_ORIGINS', 'QUEUE_CONNECTION',
            'PAYSTACK_SECRET_KEY', 'PAYSTACK_BASE_URL', 'SANCTUM_TOKEN_EXPIRY_MINUTES',
            'MARKETPLACE_COMMISSION_BPS', 'MARKETPLACE_MIN_PAYOUT_KOBO', 'MARKETPLACE_PAYOUT_ACCOUNT_HOLD_HOURS',
        ] as $key) {
            $this->assertMatchesRegularExpression('/^'.$key.'=/m', $example, "{$key} is missing from .env.example");
        }

        // Secrets are filled in on the server, never committed.
        foreach (['APP_KEY', 'PAYSTACK_SECRET_KEY'] as $secret) {
            $this->assertMatchesRegularExpression('/^'.$secret.'=\s*$/m', $example, "{$secret} must be empty in .env.example");
        }
    }
}