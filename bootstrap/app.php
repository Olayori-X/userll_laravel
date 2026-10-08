<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'audit' => \App\Http\Middleware\AuditAdminActions::class,
        ]);

        // Behind nginx, a load balancer or Cloudflare, the request reaches Laravel from the proxy, not
        // the user. Without this, $request->ip() is the proxy's address, so IP rate limits are shared
        // by everyone, audit-log IPs are wrong, and https links can be built as http.
        // TRUSTED_PROXIES is a comma-separated list of proxy addresses, or * to trust whatever
        // connects. Leave it empty locally.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
    })
    
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn ($request, $e) => $request->is('api/*') || $request->expectsJson());
    })->create();