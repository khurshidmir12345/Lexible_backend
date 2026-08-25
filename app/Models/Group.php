<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Group extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function path(): BelongsTo
    {
        return $this->belongsTo(Path::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_members')
            ->withPivot('status', 'joined_at')
            ->wherePivot('status', 'active');
    }

    public function pending(): HasMany
    {
        return $this->memberships()->where('status', 'pending');
    }

    public function refreshMembersCount(): void
    {
        $this->updateQuietly(['members_count' => $this->students()->count()]);
    }
}
