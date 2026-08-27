<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Path;
use App\Models\PathStage;
use App\Models\Word;
use App\Services\Dictionary\DictionaryService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PathController extends Controller
{
    public function index(Request $request): array
    {
        $this->authorizeTeacher($request);

        $paths = $request->user()->paths()->with('stages')->withCount('groups')->get();

        return [
            'paths' => $paths->map(fn (Path $path) => [
                'id' => $path->id,
                'title' => $path->title,
                'subtitle' => $path->subtitle,
                'emoji' => $path->emoji,
                'stages_count' => $path->stages->count(),
                // UT-01b reads out "8 bosqich · 96 soʼz · 2 guruhga biriktirilgan".
                'words_count' => (int) $path->stages->sum('words_count'),
                'groups_count' => (int) $path->groups_count,
                'stages' => $path->stages->map(fn (PathStage $stage) => [
                    'id' => $stage->id,
                    'position' => $stage->position,
                    'title' => $stage->title,
                    'type' => $stage->type,
                    'words_count' => $stage->words_count,
                ])->values(),
            ])->values(),
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

    public function update(Request $request, Path $path): array
    {
        $this->authorizeOwner($request, $path);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:60'],
            'subtitle' => ['nullable', 'string', 'max:80'],
        ]);

        $path->update($data);

        return ['path' => ['id' => $path->id, 'title' => $path->title, 'subtitle' => $path->subtitle]];
    }

    /**
     * A path in use by a group would pull the stages out from under a class
     * mid-term, so it has to be detached first.
     */
    public function destroy(Request $request, Path $path): array
    {
        $this->authorizeOwner($request, $path);

        abort_if(
            $path->groups()->exists(),
            Response::HTTP_CONFLICT,
            'Bu yoʼl guruhga biriktirilgan — avval guruhdan uzing.',
        );

        $path->delete();

        return ['deleted' => true];
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
            'type' => ['sometimes', 'in:normal,exam'],
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

        $patch = [];
        if (array_key_exists('title', $data)) {
            $patch['title'] = $data['title'];
        }
        if (array_key_exists('type', $data)) {
            $patch['type'] = $data['type'];
        }
        if ($patch) {
            $stage->update($patch);
        }

        // Classes already holding this stage get the new words straight away.
        $this->syncToClasses($stage);

        return ['stage' => $this->present($stage->fresh())];
    }

    /**
     * Deleting a stage closes the gap in the numbering, otherwise the map
     * would show "1, 2, 4".
     */
    public function destroyStage(Request $request, PathStage $stage): array
    {
        $this->authorizeOwner($request, $stage->path);

        $path = $stage->path;
        $stage->delete();

        foreach ($path->stages()->orderBy('position')->get() as $index => $row) {
            if ($row->position !== $index + 1) {
                $row->updateQuietly(['position' => $index + 1]);
            }
        }

        $path->refreshStagesCount();

        return ['deleted' => true];
    }

    /**
     * A stage the teacher edits has already been copied into every student's
     * own category list, so the copies are re-synced in place — progress on a
     * word that survived the edit is untouched.
     */
    protected function syncToClasses(PathStage $stage): void
    {
        $categories = Category::where('path_stage_id', $stage->id)->get();

        if ($categories->isEmpty()) {
            return;
        }

        $ids = $stage->words()->pluck('words.id')
            ->values()
            ->mapWithKeys(fn ($id, $i) => [$id => ['sort_order' => $i, 'created_at' => now()]])
            ->all();

        foreach ($categories as $category) {
            $category->words()->sync($ids);
            $category->refreshWordsCount();

            if ($stage->title && $category->title !== $stage->title) {
                $category->updateQuietly(['title' => $stage->title]);
            }
        }
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
