# Userll API: Step 1 (foundation)

This folder holds only the files specific to Userll. They go on top of a fresh Laravel project.

## Setup

```bash
composer create-project laravel/laravel userll-api
cd userll-api
php artisan install:api          # installs Sanctum, creates routes/api.php
```

Copy everything from this folder into the project, **overwriting**:
- `routes/api.php`
- `app/Providers/AppServiceProvider.php`
- `app/Models/User.php`

Then:

1. In `bootstrap/app.php`, inside `->withExceptions(...)`, add so the API always returns JSON errors:
   ```php
   $exceptions->shouldRenderJsonWhen(fn ($request, $e) => $request->is('api/*') || $request->expectsJson());
   ```
2. In `.env` set the database, mail, and:
   ```
   APP_URL=http://localhost:8000      # public URL of this API (verification links use it)
   FRONTEND_URL=http://localhost:3000 # the Next.js app
   ```
3. Run:
   ```bash
   php artisan migrate
   php artisan db:seed --class=CategorySeeder
   php artisan test
   ```
4. Make yourself admin: `php artisan marketplace:make-admin you@example.com`

## Before production
- `config/sanctum.php`: set `expiration` (minutes) so tokens do not live forever.
- `php artisan config:publish cors`, then limit `allowed_origins` to your Next.js domain.
- Replace the placeholder numbers in `config/marketplace.php` (commission, ship deadline, auto-release days).

## Not run yet
Only `Money` was executed and tested here (no Composer/Packagist access in my sandbox). Every other file passes `php -l` (syntax) but has not been run against a real Laravel install, so `migrate` and `php artisan test` are the first real checks.
