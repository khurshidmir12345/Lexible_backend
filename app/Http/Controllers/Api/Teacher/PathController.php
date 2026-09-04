<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Path;
use App\Models\PathStage;
use App\Models\Word;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class PathController extends Controller
{
    /** A path is born with a full road: this many stages, waiting to be filled. */
    protected const INITIAL_STAGES = 10;

    public function index(Request $request): array
    {
        $this->authorizeTeacher($request);

        $paths = $request->user()->paths()->with('stages')->withCount('groups')->get();

        // Older paths were built one stage at a time; the map now comes
        // pre-drawn, so short paths are quietly topped up to the full road.
        foreach ($paths as $path) {
            if ($path->stages->count() < self::INITIAL_STAGES) {
                $this->seedStages($path, self::INITIAL_STAGES - $path->stages->count());
                $path->load('stages');
            }
        }

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

        // The whole road appears at once — the teacher fills stage 1 and the
        // next card unlocks, instead of the map forming one node at a time.
        $this->seedStages($path, self::INITIAL_STAGES);

        return ['path' => ['id' => $path->id, 'title' => $path->title, 'subtitle' => $path->subtitle]];
    }

    protected function seedStages(Path $path, int $count): void
    {
        $position = (int) $path->stages()->max('position');

        for ($i = 0; $i < $count; $i++) {
            $path->stages()->create(['position' => ++$position, 'type' => 'normal']);
        }

        $path->refreshStagesCount();
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
     * A stage's vocabulary is picked from the shared dictionary — the teacher
     * searches or takes a random batch, never types pairs by hand. There is
     * no cap: a stage holds as many words as the lesson needs.
     */
    public function updateStage(Request $request, PathStage $stage): array
    {
        $this->authorizeOwner($request, $stage->path);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:60'],
            'type' => ['sometimes', 'in:normal,exam'],
            'words' => ['required', 'array'],
            'words.*' => ['integer', 'exists:words,id'],
        ]);

        $ids = collect($data['words'])
            ->unique()
            ->values()
            ->mapWithKeys(fn (int $id, int $index) => [$id => ['sort_order' => $index]])
            ->all();

        // Students cannot add to a taught stage, so one too small to play
        // would be a dead end on their road.
        $min = (int) config('game.session.min_words');
        abort_if(count($ids) < $min, Response::HTTP_UNPROCESSABLE_ENTITY,
            "Bosqichda kamida {$min} ta soʼz boʼlishi kerak.");

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

        // Classes already holding this stage get the new words straight away,
        // and a stage that just got its first words reaches students who were
        // materialised before it was written.
        $this->syncToClasses($stage);
        $this->handDownNewStages($stage);

        return ['stage' => $this->present($stage->fresh())];
    }

    protected function handDownNewStages(PathStage $stage): void
    {
        $groups = app(\App\Services\Teaching\GroupService::class);

        foreach ($stage->path->groups()->with('students')->get() as $group) {
            foreach ($group->students as $student) {
                $groups->materialise($group, $student);
            }
        }
    }

    /**
     * A random batch for the stage editor: the teacher names a level and a
     * count, the dictionary answers. Words at the exact CEFR level first;
     * common words (by frequency) top the batch up when the level runs dry.
     */
    public function randomWords(Request $request): array
    {
        $this->authorizeTeacher($request);

        $data = $request->validate([
            'level' => ['nullable', Rule::in(['A0', 'A1', 'A2', 'B1', 'B2', 'C1'])],
            'count' => ['required', 'integer', 'min:1', 'max:100'],
            'exclude' => ['nullable', 'string'],   // comma-separated word ids
        ]);

        $locale = $request->user()->native_lang;
        $exclude = collect(explode(',', $data['exclude'] ?? ''))
            ->filter(fn ($v) => is_numeric($v))
            ->map(fn ($v) => (int) $v);

        $base = fn () => Word::usable($locale)->whereNotIn('id', $exclude);

        $atLevel = ! empty($data['level'])
            ? $base()->where('cefr_level', $data['level'])->inRandomOrder()->limit($data['count'])->get()
            : collect();

        $picked = $atLevel;

        if ($picked->count() < $data['count']) {
            $filler = $base()
                ->whereNotIn('id', $picked->pluck('id'))
                ->orderBy('frequency_rank')
                ->limit(($data['count'] - $picked->count()) * 4)
                ->get()
                ->shuffle()
                ->take($data['count'] - $picked->count());

            $picked = $picked->concat($filler);
        }

        return [
            'words' => $picked->values()->map(fn (Word $word) => [
                'id' => $word->id,
                'en' => $word->word,
                'translation' => $word->translation($locale),
                'emoji' => $word->emoji,
                'icon' => $word->icon_url,
                'icon_large' => $word->icon_large_url,
            ]),
        ];
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
            'words' => $stage->words->map(fn (Word $word) => [
                'id' => $word->id,
                'en' => $word->word,
                'translation' => $word->translation($locale),
                'emoji' => $word->emoji,
                'icon' => $word->icon_url,
                'icon_large' => $word->icon_large_url,
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
