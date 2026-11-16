<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTION_CHAT_QUERY = 'chat.query';

    public const ACTION_CHAT_QUERY_FAILED = 'chat.query_failed';

    protected $fillable = [
        'organization_id',
        'user_id',
        'action',
        'prompt',
        'retrieved_document_ids',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'retrieved_document_ids' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Append-only. Database triggers enforce the same rule for raw queries.
        static::updating(fn () => throw new LogicException('Audit logs are immutable and cannot be updated.'));
        static::deleting(fn () => throw new LogicException('Audit logs are immutable and cannot be deleted.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
