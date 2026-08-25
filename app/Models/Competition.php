<?php

namespace App\Models;

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
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
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
        $bot = ltrim((string) config('telegram.username'), '@');
        $short = config('telegram.mini_app.short_name');

        return "https://t.me/{$bot}/{$short}?startapp=comp_{$this->code}";
    }
}
