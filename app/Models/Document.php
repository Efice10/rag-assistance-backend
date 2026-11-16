<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_INDEXED = 'indexed';

    public const STATUS_FAILED = 'failed';

    public const CLEARANCE_LABELS = [
        1 => 'Public',
        2 => 'Internal',
        3 => 'Confidential',
        4 => 'Restricted',
    ];

    protected $fillable = [
        'organization_id',
        'department_id',
        'uploaded_by',
        'title',
        'file_path',
        'mime_type',
        'clearance_level',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'clearance_level' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * The single definition of which documents a user may see. Mirrors the
     * WHERE clause the AI service applies to vector search.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query
            ->where('organization_id', $user->organization_id)
            ->where('clearance_level', '<=', $user->clearance_level)
            ->when(! $user->isAdmin(), fn (Builder $q) => $q->where('department_id', $user->department_id));
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'mime_type' => $this->mime_type,
            'clearance_level' => $this->clearance_level,
            'clearance_label' => self::CLEARANCE_LABELS[$this->clearance_level] ?? 'Unknown',
            'status' => $this->status,
            'error_message' => $this->error_message,
            'department' => $this->department?->only(['id', 'name']),
            'uploaded_by' => $this->uploader?->only(['id', 'name']),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
