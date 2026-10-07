# Userll API

The backend of the Userll marketplace: a Laravel API with accounts, listings, cart and checkout, Paystack payments
held in escrow, seller payouts, identity checks, reviews, chat, notifications and an admin API. The website is a
separate Next.js app; this project has no pages of its own.

All money is stored in **kobo** (100 kobo = ₦1) as whole numbers.

## Requirements

PHP 8.3+, Composer, a database (SQLite for local work, MySQL or Postgres in production), and a Paystack account.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed --class=CategorySeeder
php artisan marketplace:make-admin you@example.com   # after registering that account
php artisan test
```

Fill in `.env` (see the comments in `.env.example`). The ones that matter first: `APP_URL`, `FRONTEND_URL`,
`CORS_ALLOWED_ORIGINS`, `PAYSTACK_SECRET_KEY`, and a real mail service for `MAIL_*`.

## Running it

Three things must be running, locally and in production:

| What | Command | Why |
|---|---|---|
| The web server | `php artisan serve` (production: nginx or Apache + PHP-FPM) | Serves the API |
| A queue worker | `php artisan queue:work` | Sends refunds, payouts and emails. Without it, none of those move |
| The scheduler | locally `php artisan schedule:work`; on a server a cron line `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1` | Runs the automatic jobs below |

In production keep the worker alive with Supervisor (or systemd) and restart it after each deploy with
`php artisan queue:restart`. Check failed jobs with `php artisan queue:failed`.

### Automatic jobs

| Command | Every | What it does |
|---|---|---|
| `marketplace:release-due-orders` | 15 min | Releases escrow for shipped orders whose auto-release time has passed |
| `marketplace:cancel-overdue-orders` | 15 min | Cancels and refunds paid orders the seller did not ship in time |
| `marketplace:expire-checkouts` | 5 min | Closes checkouts that stayed unpaid too long |
| `marketplace:reconcile-payouts` | 5 min | Asks Paystack about payouts nobody has confirmed and settles them |
| `sanctum:prune-expired --hours=24` | daily | Deletes expired login tokens |

## Paystack

1. Put your `sk_test_` key in `PAYSTACK_SECRET_KEY`. Switch to the live key only when you are ready to take real money.
2. In the Paystack dashboard, set the webhook URL to `https://<your-api-domain>/api/v1/webhooks/paystack`.
3. For payouts: enable transfers, **turn off the transfer OTP requirement** (otherwise API transfers wait for
   someone to approve them in the dashboard), keep the Paystack balance funded, and check whether live mode needs
   an IP allow-list for transfers.
4. Paystack's account-name lookup may be limited with test keys. The bank-account flow is fully tested against a
   fake, but try it once with a live key before launch.

## How money moves

1. A buyer pays a checkout (one order per seller). The payment is confirmed by asking Paystack, never by trusting
   the browser or the webhook body.
2. The seller's share and our commission sit in **escrow** (pending balances in the ledger).
3. When the buyer confirms delivery, or the auto-release time passes, the seller's share becomes available and the
   commission becomes ours. A dispute freezes the order until an admin decides.
4. The seller withdraws their available balance. The amount leaves their balance at once; a queued job sends the
   transfer; Paystack's result (or the reconcile job) settles it. A failed transfer returns the money automatically.
5. Refunds go back through Paystack and are tracked until Paystack reports them finished.

Every balance change is a row in the ledger, written inside a database transaction.

## Where things are

- `routes/api.php`: every endpoint, grouped as public catalog, buying, seller area, inbox and chat, and admin.
- `app/Services`: the rules (payments, ledger, refunds, payouts, KYC, reviews, chat, notifications, moderation).
- `app/Http/Controllers/Api/V1`: thin controllers; `app/Http/Resources`: what each audience is allowed to see.
- `app/Services/Notifier.php`: the only place that decides who is told what, and the wording.
- Rate limits are named per action in `AppServiceProvider` (unnamed `throttle:N,1` limits would share one counter).

## Identity documents (KYC)

Photos are stored privately under `storage/app/private` until all four `CLOUDFLARE_R2_*` variables are set, then in
Cloudflare R2. Each submission remembers which disk holds its photo, so switching later never loses old photos.
Admins view a photo through an authenticated endpoint; there is no public link.

## Admin

`marketplace:make-admin <email>` promotes an existing account. Every admin action that changes something, and every
view of a private document or disputed chat, is written to the append-only audit log (`GET /api/v1/admin/audit-logs`).

## Before going live

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, a real `APP_KEY`, a real database and real mail
- [ ] `CORS_ALLOWED_ORIGINS` set to your website's address
- [ ] Queue worker and scheduler running, and restarted on deploy
- [ ] Paystack: live key, webhook URL, transfers enabled, OTP off, balance funded
- [ ] A small real-money test: one payment, one refund, one payout
- [ ] `php artisan config:cache` and `php artisan route:cache` (re-run `config:cache` after any `.env` change)
- [ ] Backups for the database and for `storage/app/private`
- [ ] Decide a retention period for rejected identity documents