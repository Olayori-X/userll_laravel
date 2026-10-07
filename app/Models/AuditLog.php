<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One admin action. Append-only: rows are added by the audit middleware and never changed or removed. */
class AuditLog extends Model
{
    public $timestamps = false; // only created_at exists, and it is set once

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'route_params' => 'array',
            'input' => 'array',
            'status' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log entries cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Audit log entries cannot be deleted.'));
    }

    public function admin(): BelongsTo { return $this->belongsTo(User::class, 'admin_id'); }
}