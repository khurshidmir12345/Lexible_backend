<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PathStage extends Model
{
    protected $guarded = ['id'];

    public function path(): BelongsTo
    {
        return $this->belongsTo(Path::class);
    }

    public function words(): BelongsToMany
    {
        return $this->belongsToMany(Word::class, 'path_stage_word')
            ->withPivot('sort_order')
            ->orderBy('path_stage_word.sort_order');
    }

    public function refreshWordsCount(): void
    {
        $this->updateQuietly(['words_count' => $this->words()->count()]);
    }
}
