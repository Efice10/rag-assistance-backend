<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['organization_id', 'department_id', 'name', 'email', 'password', 'role', 'clearance_level'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_MANAGER = 'manager';

    public const ROLE_STAFF = 'staff';

    public const ROLES = [self::ROLE_ADMIN, self::ROLE_MANAGER, self::ROLE_STAFF];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
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

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function canUploadDocuments(): bool
    {
        return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_MANAGER], true);
    }

    /**
     * The retrieval scope sent to the AI service. It is always built here,
     * from the authenticated user, and never from request input.
     *
     * @return array{organization_id: int, department_id: int, clearance_level: int, is_admin: bool}
     */
    public function retrievalScope(): array
    {
        return [
            'organization_id' => (int) $this->organization_id,
            'department_id' => (int) $this->department_id,
            'clearance_level' => (int) $this->clearance_level,
            'is_admin' => $this->isAdmin(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function profile(): array
    {
        $this->loadMissing(['organization:id,name,slug', 'department:id,name']);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'clearance_level' => $this->clearance_level,
            'organization' => $this->organization?->only(['id', 'name', 'slug']),
            'department' => $this->department?->only(['id', 'name']),
            'can_upload' => $this->canUploadDocuments(),
        ];
    }
}
