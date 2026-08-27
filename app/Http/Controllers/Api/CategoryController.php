<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        $category->loadMissing(['group.teacher', 'pathStage']);

        // A stage arrives pre-filled: the player asked for N words a day during
        // onboarding, so that is what a stage is. They can still add their own.
        // A teacher's stage is never touched — an empty one is a lesson that
        // has not been written yet, not an invitation to pick random words.
        $filled = $category->isFromGroup() ? 0 : $picker->fill($category);

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
                // Inside a teacher's path the stage keeps the class numbering,
                // so "3-bosqich" means the same lesson to everyone.
                'position' => $category->pathStage?->position ?? $category->position,
                'title' => $category->title,
                'type' => $category->type,
                'status' => $category->status,
                'progress' => $category->progress,
                'practiced' => $category->practiced,
                // The vocabulary belongs to whoever wrote it. A teacher's
                // stage is read-only here; the app hides its edit controls.
                'from_group' => $category->isFromGroup(),
                'editable' => ! $category->isFromGroup(),
                'group' => $category->group ? [
                    'id' => $category->group->id,
                    'title' => $category->group->title,
                    'badge' => $category->group->badge,
                    'teacher' => $category->group->teacher?->full_name,
                ] : null,
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
        $this->authorizeEditable($request, $category);

        $data = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:60'],
        ]);

        $category->update($data);

        return ['category' => ['id' => $category->id, 'title' => $category->title]];
    }

    /** Toggling a word in the "add words" screen. */
    public function attach(Request $request, Category $category, RoadMapService $road): array
    {
        $this->authorizeEditable($request, $category);

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
        $this->authorizeEditable($request, $category);

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

    /**
     * The player owns their copy of a teacher's stage, but not its contents —
     * the class has to be studying the same words for the teacher's results
     * screen to mean anything.
     */
    protected function authorizeEditable(Request $request, Category $category): void
    {
        $this->authorizeOwner($request, $category);

        abort_if(
            $category->isFromGroup(),
            Response::HTTP_FORBIDDEN,
            'Bu bosqich ustozingiz tuzgan — lugʼatini oʼzgartirib boʼlmaydi.',
        );
    }
}
