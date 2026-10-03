<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ada Obi',
            'email' => 'ada@example.com',
            'phone' => '+2348012345678',
            'password' => 'Secret123',
            'password_confirmation' => 'Secret123',
        ], $overrides);
    }

    public function test_register_creates_user_sends_verification_and_returns_token(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertCreated()
            ->assertJsonStructure(['user' => ['id', 'email'], 'token']);

        $user = User::where('email', 'ada@example.com')->firstOrFail();
        $this->assertSame('user', $user->role->value);
        $this->assertNotSame('Secret123', $user->password);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_register_cannot_set_role(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['role' => 'admin']))->assertCreated();

        $this->assertSame('user', User::first()->role->value);
    }

    public function test_register_rejects_weak_password_and_duplicate_email(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['password' => 'weak', 'password_confirmation' => 'weak']))
            ->assertUnprocessable();

        User::factory()->create(['email' => 'ada@example.com']);
        $this->postJson('/api/v1/auth/register', $this->payload())->assertUnprocessable();
    }

    public function test_login_success_and_wrong_password(): void
    {
        User::factory()->create(['email' => 'ada@example.com', 'password' => 'Secret123']);

        $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'Secret123'])
            ->assertOk()->assertJsonStructure(['token']);

        $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'nope'])
            ->assertUnauthorized();
    }

    public function test_suspended_user_cannot_log_in(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com', 'password' => 'Secret123']);
        $user->forceFill(['status' => UserStatus::Suspended])->save();

        $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'Secret123'])
            ->assertForbidden();
    }

    public function test_me_requires_auth_and_logout_revokes_token(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();

        User::factory()->create(['email' => 'ada@example.com', 'password' => 'Secret123']);
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'Secret123'])
            ->json('token');

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', 'ada@example.com');
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_email_verification_link_verifies_user(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->get($url)->assertRedirect();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_verification_link_with_bad_hash_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1('someone-else@example.com'),
        ]);

        $this->get($url)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_forgot_password_gives_same_reply_for_unknown_email(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk();
        Notification::assertNothingSent();
    }

    public function test_password_reset_changes_password_and_revokes_tokens(): void
    {
        $user = User::factory()->create(['password' => 'OldSecret1']);
        $user->createToken('web');
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewSecret1',
            'password_confirmation' => 'NewSecret1',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'NewSecret1'])->assertOk();
        $this->assertSame(1, $user->tokens()->count()); // only the fresh login token remains
    }
}
