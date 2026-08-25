<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A teacher's curriculum — an ordered set of stages they fill themselves. */
class Path extends Model
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

    public function stages(): HasMany
    {
        return $this->hasMany(PathStage::class)->orderBy('position');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    public function refreshStagesCount(): void
    {
        $this->updateQuietly(['stages_count' => $this->stages()->count()]);
    }
}
