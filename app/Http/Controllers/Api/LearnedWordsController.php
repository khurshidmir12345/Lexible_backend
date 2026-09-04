<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WordProgress;
use Illuminate\Http\Request;

class LearnedWordsController extends Controller
{
    /**
     * Everything the player has actually learned, kept apart from the stages
     * they came from — a stage is a session, this is the collection that
     * survives it.
     */
    public function __invoke(Request $request): array
    {
        $user = $request->user();
        $threshold = config('game.mastery.learned_at');

        $data = $request->validate([
            'filter' => ['nullable', 'in:learned,learning,all'],
        ]);

        $filter = $data['filter'] ?? 'learned';

        $query = WordProgress::with('word')
            ->where('user_id', $user->id)
            ->when($filter === 'learned', fn ($q) => $q->where('overall', '>=', $threshold))
            ->when($filter === 'learning', fn ($q) => $q->where('overall', '<', $threshold)->where('overall', '>', 0))
            ->orderByDesc('overall')
            ->orderByDesc('last_practiced_at');

        $counts = WordProgress::where('user_id', $user->id)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN overall >= ? THEN 1 ELSE 0 END) as learned', [$threshold])
            ->first();

        return [
            'counts' => [
                'learned' => (int) ($counts->learned ?? 0),
                'learning' => (int) (($counts->total ?? 0) - ($counts->learned ?? 0)),
                'total' => (int) ($counts->total ?? 0),
            ],
            'words' => $query->limit(300)->get()->map(fn (WordProgress $p) => [
                'id' => $p->word_id,
                'en' => $p->word->word,
                'translation' => $p->word->translation($user->native_lang),
                'pos' => $p->word->part_of_speech,
                'transcription' => $p->word->transcription,
                'audio' => $p->word->audio_url,
                'emoji' => $p->word->emoji,
                'icon' => $p->word->icon_url,
                'icon_large' => $p->word->icon_large_url,
                'example' => $p->word->example['en'] ?? null,
                'example_translation' => $p->word->example[$user->native_lang] ?? null,
                'overall' => $p->overall,
                'mastery' => $p->mastery(),
                'learned_at' => $p->is_learned ? $p->last_practiced_at?->toDateString() : null,
            ])->values(),
        ];
    }
}
