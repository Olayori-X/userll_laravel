<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Records what admins do. Attach it to the whole admin route group as "audit": every request that changes
 * something is logged. For sensitive reads (an ID photo, a private chat) add "audit:read" to that one route.
 */
class AuditAdminActions
{
    /** Never stored, wherever they appear in what the admin sent. */
    private const SECRET_KEYS = ['password', 'password_confirmation', 'current_password', 'token', 'secret', 'authorization'];

    public function handle(Request $request, Closure $next, string $mode = 'writes'): Response
    {
        $response = $next($request);

        $isRead = in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);

        // "writes" logs only requests that change things; "read" logs only the reads we mark as sensitive.
        if (($mode === 'read') === $isRead) {
            $this->record($request, $response);
        }

        return $response;
    }

    private function record(Request $request, Response $response): void
    {
        try {
            $route = $request->route();

            AuditLog::create([
                'admin_id' => $request->user()?->id,
                'action' => Str::limit($request->method().' '.($route?->uri() ?? $request->path()), 150, ''),
                'method' => $request->method(),
                'path' => Str::limit($request->path(), 255, ''),
                'route_params' => $route ? $this->routeParams($route->parameters()) : null,
                'input' => $this->scrub($request->except(self::SECRET_KEYS)) ?: null,
                'status' => $response->getStatusCode(),
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
            ]);
        } catch (Throwable $e) {
            // The admin's action has already happened and cannot be undone here, so do not fail the response.
            // This must be noticed and fixed: an action without a log row is a gap in the record.
            Log::critical('Could not write an audit log entry', [
                'admin_id' => $request->user()?->id,
                'method' => $request->method(),
                'path' => $request->path(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Which records the route touched, as plain ids. @return array<string, scalar|null> */
    private function routeParams(array $parameters): array
    {
        return collect($parameters)
            ->map(fn ($value) => is_object($value) && method_exists($value, 'getKey') ? $value->getKey() : (is_scalar($value) ? $value : null))
            ->all();
    }

    /** Strip secrets (including nested ones), shorten long text and drop anything that is not plain data. */
    private function scrub(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (in_array(Str::lower((string) $key), self::SECRET_KEYS, true)) {
                $clean[$key] = '[hidden]';
            } elseif (is_array($value)) {
                $clean[$key] = $this->scrub($value);
            } elseif (is_string($value)) {
                $clean[$key] = Str::limit($value, 500);
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            } else {
                $clean[$key] = '[not stored]'; // uploaded files and objects
            }
        }

        return $clean;
    }
}