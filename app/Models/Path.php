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
        return ['is_active' => 'boolean', 'types' => 'array'];
    }

    /**
     * The exercises a student may play on this path's stages. A teacher who
     * has not chosen leaves every game open.
     *
     * @return list<string>
     */
    public function allowedTypes(): array
    {
        $chosen = array_values(array_intersect(config('game.test_types'), $this->types ?? []));

        return $chosen ?: config('game.test_types');
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
