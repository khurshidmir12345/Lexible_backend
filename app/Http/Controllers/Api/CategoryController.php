<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WordResource;
use App\Models\Category;
use App\Models\Word;
use App\Models\WordProgress;
use App\Services\Game\RoadMapService;
use App\Services\Game\WordPicker;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CategoryController extends Controller
{
    public function show(Request $request, Category $category, WordPicker $picker): array
    {
        $this->authorizeOwner($request, $category);

        // A stage arrives pre-filled: the player asked for N words a day during
        // onboarding, so that is what a stage is. They can still add their own.
        $filled = $picker->fill($category);

        if ($filled > 0) {
            $category->refresh();
        }

        $words = $category->words()->get();

        // One query for the whole category rather than one per word.
        $progress = WordProgress::where('user_id', $request->user()->id)
            ->whereIn('word_id', $words->pluck('id'))
            ->get()
            ->keyBy('word_id');

        $locale = $request->user()->native_lang;

        return [
            'category' => [
                'id' => $category->id,
                'position' => $category->position,
                'title' => $category->title,
                'type' => $category->type,
                'status' => $category->status,
                'progress' => $category->progress,
                'practiced' => $category->practiced,
            ],
            'words' => $words->map(function (Word $word) use ($progress, $locale) {
                $p = $progress[$word->id] ?? null;

                return [
                    'id' => $word->id,
                    'en' => $word->word,
                    'translation' => $word->translation($locale),
                    'pos' => $word->part_of_speech,
                    'transcription' => $word->transcription,
                    'audio' => $word->audio_url,
                    'emoji' => $word->emoji,
                    'icon' => $word->icon_path,
                    'definition' => $word->definition[$locale] ?? $word->definition['en'] ?? null,
                    'example' => $word->example['en'] ?? null,
                    'example_translation' => $word->example[$locale] ?? null,
                    'mastery' => $p ? $p->mastery() : array_fill_keys(WordProgress::DIMENSIONS, 0),
                    'overall' => $p?->overall ?? 0,
                ];
            })->values(),
            'mastery_by_type' => $this->masteryByType($progress),
            'auto_filled' => $filled,
        ];
    }

    /** A node has no title until the player names it on first open. */
    public function rename(Request $request, Category $category): array
    {
        $this->authorizeOwner($request, $category);

        $data = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:60'],
        ]);

        $category->update($data);

        return ['category' => ['id' => $category->id, 'title' => $category->title]];
    }

    /** Toggling a word in the "add words" screen. */
    public function attach(Request $request, Category $category, RoadMapService $road): array
    {
        $this->authorizeOwner($request, $category);

        $data = $request->validate([
            'word_id' => ['required', 'integer', 'exists:words,id'],
        ]);

        $category->words()->syncWithoutDetaching([
            $data['word_id'] => [
                'sort_order' => $category->words()->count(),
                'created_at' => now(),
            ],
        ]);

        $road->refreshProgress($category);

        return ['words_count' => $category->fresh()->words_count];
    }

    public function detach(Request $request, Category $category, Word $word, RoadMapService $road): array
    {
        $this->authorizeOwner($request, $category);

        $category->words()->detach($word->id);
        $road->refreshProgress($category);

        return ['words_count' => $category->fresh()->words_count];
    }

    /** Which exercises are weak across the whole category. */
    protected function masteryByType($progress): array
    {
        return collect(WordProgress::DIMENSIONS)
            ->mapWithKeys(fn (string $dimension) => [
                $dimension => $progress->isEmpty()
                    ? 0
                    : (int) round($progress->avg('m_'.$dimension)),
            ])
            ->all();
    }

    protected function authorizeOwner(Request $request, Category $category): void
    {
        abort_unless($category->user_id === $request->user()->id, Response::HTTP_FORBIDDEN);
    }
}
