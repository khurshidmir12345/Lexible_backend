<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Path;
use App\Models\PathStage;
use App\Models\Word;
use App\Services\Dictionary\DictionaryService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class PathController extends Controller
{
    public function index(Request $request): array
    {
        $this->authorizeTeacher($request);

        return [
            'paths' => $request->user()->paths()->with('stages')->get()->map(fn (Path $path) => [
                'id' => $path->id,
                'title' => $path->title,
                'subtitle' => $path->subtitle,
                'emoji' => $path->emoji,
                'stages_count' => $path->stages->count(),
                'stages' => $path->stages->map(fn (PathStage $stage) => [
                    'id' => $stage->id,
                    'position' => $stage->position,
                    'title' => $stage->title,
                    'type' => $stage->type,
                    'words_count' => $stage->words_count,
                ]),
            ]),
        ];
    }

    public function store(Request $request): array
    {
        $this->authorizeTeacher($request);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:60'],
            'subtitle' => ['nullable', 'string', 'max:80'],
        ]);

        $path = $request->user()->paths()->create($data)->fresh();

        return ['path' => ['id' => $path->id, 'title' => $path->title, 'subtitle' => $path->subtitle]];
    }

    /** Stages are unlimited; each is added at the end of the path. */
    public function addStage(Request $request, Path $path): array
    {
        $this->authorizeOwner($request, $path);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:60'],
            'type' => ['nullable', 'in:normal,exam'],
        ]);

        $stage = $path->stages()->create([
            'position' => (int) $path->stages()->max('position') + 1,
            'title' => $data['title'] ?? null,
            'type' => $data['type'] ?? 'normal',
        ])->fresh();

        $path->refreshStagesCount();

        return ['stage' => ['id' => $stage->id, 'position' => $stage->position, 'title' => $stage->title]];
    }

    public function showStage(Request $request, PathStage $stage): array
    {
        $this->authorizeOwner($request, $stage->path);

        return ['stage' => $this->present($stage)];
    }

    /**
     * The teacher types the word and its translation. A word already in the
     * shared dictionary is reused; anything new is created from what they
     * typed, so a class is never blocked by a gap in the dictionary.
     */
    public function updateStage(Request $request, PathStage $stage, DictionaryService $dictionary): array
    {
        $this->authorizeOwner($request, $stage->path);

        $max = config('game.teaching.max_words_per_stage');

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:60'],
            'words' => ['required', 'array', 'max:'.$max],
            'words.*.en' => ['required', 'string', 'max:60'],
            'words.*.translation' => ['required', 'string', 'max:120'],
        ]);

        $locale = $request->user()->native_lang;
        $ids = [];

        foreach ($data['words'] as $index => $row) {
            $word = $dictionary->find($row['en']) ?? $dictionary->import($row['en']);

            if (! $word) {
                $word = Word::create([
                    'word' => trim($row['en']),
                    'source' => 'manual',
                    'needs_review' => true,
                ]);
            }

            // The teacher's wording wins for their own class.
            $translations = $word->translations ?? [];
            $existing = $translations[$locale] ?? [];
            $existing = is_array($existing) ? $existing : [$existing];

            if (! in_array($row['translation'], $existing, true)) {
                array_unshift($existing, trim($row['translation']));
                $translations[$locale] = array_values(array_unique($existing));
                $word->update(['translations' => $translations]);
            }

            $ids[$word->id] = ['sort_order' => $index];
        }

        $stage->words()->sync($ids);
        $stage->refreshWordsCount();

        if (array_key_exists('title', $data)) {
            $stage->update(['title' => $data['title']]);
        }

        return ['stage' => $this->present($stage->fresh())];
    }

    protected function present(PathStage $stage): array
    {
        $locale = request()->user()->native_lang;

        return [
            'id' => $stage->id,
            'position' => $stage->position,
            'title' => $stage->title,
            'type' => $stage->type,
            'path' => ['id' => $stage->path->id, 'title' => $stage->path->title, 'subtitle' => $stage->path->subtitle],
            'max_words' => config('game.teaching.max_words_per_stage'),
            'words' => $stage->words->map(fn (Word $word) => [
                'id' => $word->id,
                'en' => $word->word,
                'translation' => $word->translation($locale),
            ])->values(),
        ];
    }

    protected function authorizeTeacher(Request $request): void
    {
        abort_unless($request->user()->isTeacher(), Response::HTTP_FORBIDDEN, 'Bu boʼlim ustozlar uchun.');
    }

    protected function authorizeOwner(Request $request, Path $path): void
    {
        $this->authorizeTeacher($request);
        abort_unless($path->teacher_id === $request->user()->id, Response::HTTP_FORBIDDEN);
    }
}
