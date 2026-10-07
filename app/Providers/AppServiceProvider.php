<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Password-reset emails link to the Next.js app, not to the API.
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return rtrim(config('marketplace.frontend_url'), '/')
                .'/reset-password?token='.$token
                .'&email='.urlencode($notifiable->getEmailForPasswordReset());
        });

        // 5 attempts per minute per email+IP on register/login/forgot/reset.
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip());
        });

        // One named limiter per action, so each has its own counter. Unnamed "throttle:30,1" middleware all
        // share ONE counter per user, which makes unrelated actions (chat, payouts, KYC) block each other.
        foreach ([
            'email-resend' => 6,
            'pay' => 10,
            'verify-payment' => 20,
            'payout-account' => 10,
            'kyc-submit' => 5,
            'payout-request' => 5,
            'review-write' => 10,
            'review-reply' => 20,
            'chat-send' => 30,
            'chat-start' => 20,
        ] as $name => $perMinute) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($perMinute)->by($request->user()?->id ?: $request->ip()));
        }

        // The automatic jobs. They only run while the scheduler runs:
        // locally `php artisan schedule:work`, on a server a cron entry for `php artisan schedule:run` every minute.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('marketplace:release-due-orders')->everyFifteenMinutes()->withoutOverlapping();
            $schedule->command('marketplace:cancel-overdue-orders')->everyFifteenMinutes()->withoutOverlapping();
            $schedule->command('marketplace:expire-checkouts')->everyFiveMinutes()->withoutOverlapping();
            $schedule->command('marketplace:reconcile-payouts')->everyFiveMinutes()->withoutOverlapping();
            $schedule->command('sanctum:prune-expired --hours=24')->daily();
        });

        Gate::define('admin', fn (User $user) => $user->isAdmin() && $user->isActive());
        Gate::define('sell', fn (User $user) => $user->isActive() && $user->isSeller());
    }
}
