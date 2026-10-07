<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminAuditLogController extends Controller
{
    /**
     * Admin activity, newest first. Filters:
     *   ?admin_id=3          what one admin did
     *   ?action=kyc          actions whose route contains this text (kyc, disputes, suspend...)
     *   ?path=/12/           calls whose path contains this text, e.g. to find everything done to record 12
     *   ?method=POST         one HTTP method
     *   ?failed=1            only requests that were refused or failed (status 400 and above)
     *   ?from=2026-10-01&to=2026-10-31   a date range (both ends included)
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'admin_id' => ['sometimes', 'integer'],
            'action' => ['sometimes', 'string', 'max:100'],
            'path' => ['sometimes', 'string', 'max:100'],
            'method' => ['sometimes', 'in:GET,POST,PUT,PATCH,DELETE'],
            'failed' => ['sometimes', 'boolean'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        $logs = AuditLog::query()
            ->with('admin')
            ->when($data['admin_id'] ?? null, fn ($q, $id) => $q->where('admin_id', $id))
            ->when($data['action'] ?? null, fn ($q, $text) => $q->where('action', 'like', '%'.$this->escapeLike($text).'%'))
            ->when($data['path'] ?? null, fn ($q, $text) => $q->where('path', 'like', '%'.$this->escapeLike($text).'%'))
            ->when($data['method'] ?? null, fn ($q, $method) => $q->where('method', $method))
            ->when($request->boolean('failed'), fn ($q) => $q->where('status', '>=', 400))
            ->when($data['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', $from.' 00:00:00'))
            ->when($data['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', $to.' 23:59:59'))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return AuditLogResource::collection($logs);
    }

    public function show(int $log): AuditLogResource
    {
        return new AuditLogResource(AuditLog::with('admin')->findOrFail($log));
    }

    /** A search term is plain text, so "%" and "_" typed by the admin must not act as wildcards. */
    private function escapeLike(string $text): string
    {
        return addcslashes($text, '%_\\');
    }
}