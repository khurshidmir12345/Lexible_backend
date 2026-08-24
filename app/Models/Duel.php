<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Duel extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'types' => 'array',
            'word_ids' => 'array',
            'host_finished' => 'boolean',
            'guest_finished' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guest_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** More correct answers wins; an exact tie is broken by who finished faster. */
    public function resolveWinner(): ?int
    {
        if ($this->host_score !== $this->guest_score) {
            return $this->host_score > $this->guest_score ? $this->host_id : $this->guest_id;
        }

        if ($this->host_ms === $this->guest_ms) {
            return null; // a genuine draw
        }

        return $this->host_ms < $this->guest_ms ? $this->host_id : $this->guest_id;
    }

    public function inviteLink(): string
    {
        $bot = ltrim((string) config('telegram.username'), '@');
        $short = config('telegram.mini_app.short_name');

        return "https://t.me/{$bot}/{$short}?startapp=duel_{$this->code}";
    }
}
