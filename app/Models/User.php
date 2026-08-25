<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Telegram player. Identity comes from signed Mini App initData —
 * there is no password, and this model is never used by the admin panel.
 */
class User extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'study_days' => 'array',
            'is_telegram_premium' => 'boolean',
            'allows_write_to_pm' => 'boolean',
            'onboarded' => 'boolean',
            'dark_mode' => 'boolean',
            'has_blocked_bot' => 'boolean',
            'is_banned' => 'boolean',
            'last_practiced_date' => 'date',
            'last_seen_at' => 'datetime',
            'premium_until' => 'datetime',
        ];
    }

    public function paths(): HasMany
    {
        return $this->hasMany(Path::class, 'teacher_id');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class, 'teacher_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    public function isTeacher(): bool
    {
        return $this->role === 'teacher';
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class)->orderBy('position');
    }

    public function progress(): HasMany
    {
        return $this->hasMany(WordProgress::class);
    }

    public function testSessions(): HasMany
    {
        return $this->hasMany(TestSession::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(AppNotification::class)->latest('created_at');
    }

    public function hostedDuels(): HasMany
    {
        return $this->hasMany(Duel::class, 'host_id');
    }

    public function joinedDuels(): HasMany
    {
        return $this->hasMany(Duel::class, 'guest_id');
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(User::class, 'referred_by');
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name) ?: ($this->username ?? "#{$this->telegram_id}");
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->username ? '@'.$this->username : $this->full_name;
    }

    public function isPremium(): bool
    {
        return (bool) $this->premium_until?->isFuture();
    }

    public function getInitialAttribute(): string
    {
        return mb_strtoupper(mb_substr($this->full_name, 0, 1)) ?: 'L';
    }
}
