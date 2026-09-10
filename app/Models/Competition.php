<?php

namespace App\Models;

use App\Support\MiniAppLink;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Competition extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'types' => 'array',
            'word_ids' => 'array',
            'started_at' => 'datetime',
            'deadline_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(PathStage::class, 'path_stage_id');
    }

    public function players(): HasMany
    {
        return $this->hasMany(CompetitionPlayer::class);
    }

    public function inviteLink(): string
    {
        return MiniAppLink::to("comp_{$this->code}");
    }

    public function isLive(): bool
    {
        return in_array($this->status, ['lobby', 'playing'], true);
    }

    /** Whole seconds left on the clock; null without a clock, 0 once it has run out. */
    public function remainingSeconds(): ?int
    {
        if (! $this->deadline_at) {
            return null;
        }

        return max(0, (int) now()->diffInSeconds($this->deadline_at, false));
    }

    /** The clock has run out (with a few seconds' grace for a slow last answer). */
    public function timeIsUp(): bool
    {
        return $this->status === 'playing'
            && $this->deadline_at
            && now()->greaterThan($this->deadline_at->copy()->addSeconds(3));
    }
}
