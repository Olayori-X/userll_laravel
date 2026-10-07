<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\AuditLog */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'admin' => $this->admin ? [
                'id' => $this->admin->id,
                'name' => $this->admin->name,
                'email' => $this->admin->email,
            ] : null, // null once the admin account no longer exists
            'action' => $this->action,              // e.g. "POST api/v1/admin/kyc/{submission}/approve"
            'method' => $this->method,
            'path' => $this->path,                  // what was actually called
            'route_params' => $this->route_params,  // which records it touched
            'input' => $this->input,                // what the admin sent, with secrets removed
            'status' => $this->status,              // the HTTP status that came back
            'succeeded' => $this->status < 400,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}