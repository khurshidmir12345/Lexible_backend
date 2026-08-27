<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\TestSession;
use App\Models\Word;
use App\Models\WordProgress;
use App\Services\Game\ExamService;
use App\Services\Game\MasteryService;
use App\Services\Game\RoadMapService;
use App\Services\Game\TestBuilder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class TestController extends Controller
{
    public function __construct(
        protected TestBuilder $builder,
        protected MasteryService $mastery,
        protected RoadMapService $road,
        protected ExamService $exams,
    ) {}

    /** What an exam will ask, shown before the player commits to it. */
    public function briefing(Request $request, Category $category): array
    {
        $this->authorizeOwner($request, $category);
        abort_unless($category->type === 'exam', Response::HTTP_UNPROCESSABLE_ENTITY);

        return ['exam' => $this->exams->briefing($category)];
    }

    /** Start a round: pick the exercises, pick the words, generate questions. */
    public function start(Request $request, Category $category): array
    {
        $this->authorizeOwner($request, $category);

        // An exam fixes its own exercises, so the client sends none.
        $isExam = $category->type === 'exam';

        $data = $request->validate([
            'types' => [$isExam ? 'nullable' : 'required', 'array', $isExam ? 'min:0' : 'min:1'],
            'types.*' => [Rule::in(config('game.test_types'))],
            'scope' => ['nullable', Rule::in(['all', 'wrong'])],
        ]);

        $scope = $data['scope'] ?? 'all';

        // An exam ignores the requested types and scope: it is a fixed
        // checkpoint drawn from the stages before it.
        if ($category->type === 'exam') {
            $plan = $this->exams->plan($category);
            $types = $plan['types'];

            abort_if($plan['slices'] === [], Response::HTTP_UNPROCESSABLE_ENTITY,
                'Imtihon uchun avvalgi bosqichlarda soʼz yoʼq.');

            $questions = [];
            foreach ($plan['slices'] as $slice) {
                $questions = array_merge($questions, $this->builder->build(
                    $category, [$slice['type']], $slice['words'], $request->user()->native_lang,
                ));
            }
        } else {
            $words = $this->wordsFor($request, $category, $scope);
            $types = $data['types'];

            abort_if($words->isEmpty(), Response::HTTP_UNPROCESSABLE_ENTITY,
                'Bu kategoriyada mashq qilinadigan soʼz yoʼq.');

            $questions = $this->builder->build($category, $types, $words, $request->user()->native_lang);
        }

        // Picture questions are dropped for words nothing can illustrate, so
        // asking for only that type can legitimately come back empty. Saying
        // "translations are missing" would send the player looking in the
        // wrong place.
        abort_if(
            $questions === [],
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $types === ['image']
                ? 'Bu soʼzlar uchun rasm yoʼq — boshqa mashq turini tanlang.'
                : 'Savol tuzib boʼlmadi — soʼzlarda tarjima yetishmayapti.',
        );

        $session = TestSession::create([
            'user_id' => $request->user()->id,
            'category_id' => $category->id,
            'types' => $types,
            'scope' => $scope,
            'status' => 'active',
            'questions_count' => count($questions),
            'payload' => $questions,
            'started_at' => now(),
        ]);

        return [
            'session_id' => $session->id,
            'questions' => $this->builder->forClient($questions),
        ];
    }

    /** Grade one answer. The correct value never left the server. */
    public function answer(Request $request, TestSession $session): array
    {
        $this->authorizeSession($request, $session);
        abort_unless($session->status === 'active', Response::HTTP_CONFLICT, 'Bu sessiya tugagan.');

        $data = $request->validate([
            'question_id' => ['required', 'string'],
            'answer' => ['nullable'],
            'response_ms' => ['nullable', 'integer', 'min:0', 'max:600000'],
        ]);

        $question = collect($session->payload)->firstWhere('id', $data['question_id']);
        abort_unless($question, Response::HTTP_NOT_FOUND, 'Savol topilmadi.');

        $given = $data['answer'] ?? null;
        $isCorrect = $this->builder->isCorrect($question, $given);

        if ($question['type'] === 'match') {
            // A matching round covers several words at once, so mastery is
            // recorded per pair rather than for the round as a whole.
            $this->recordMatchPairs($session, $question, $given, $data['response_ms'] ?? 0);
        } elseif (($question['word_id'] ?? null) !== null) {
            $this->mastery->record($session, $question, $given, $isCorrect, $data['response_ms'] ?? 0);
        }

        $session->increment('answered_count');
        $session->increment($isCorrect ? 'correct_count' : 'wrong_count');

        return [
            'correct' => $isCorrect,
            'answer' => $question['answer'] ?? null,   // now safe to reveal
            'word' => [
                'en' => $question['en'] ?? null,
                'translation' => $question['translation'] ?? null,
            ],
        ];
    }

    /** Close the round, roll up the category and move the streak. */
    public function finish(Request $request, TestSession $session): array
    {
        $this->authorizeSession($request, $session);

        $data = $request->validate([
            'duration_ms' => ['nullable', 'integer', 'min:0'],
        ]);

        $session->update([
            'status' => 'finished',
            'duration_ms' => $data['duration_ms'] ?? 0,
            'finished_at' => now(),
        ]);

        $this->mastery->touchStreak($request->user());

        $category = $session->category;
        $unlocked = null;
        $accuracy = $session->answered_count > 0
            ? (int) round($session->correct_count / $session->answered_count * 100)
            : 0;
        $examPassed = null;

        if ($category) {
            $category->update(['practiced' => true]);

            if ($category->type === 'exam') {
                // An exam is judged on this attempt alone, not on mastery.
                $examPassed = $this->exams->passed($accuracy);

                if ($examPassed && $category->status !== 'completed') {
                    $category->update(['progress' => $accuracy]);
                    $unlocked = $this->road->complete($category);
                }
            } else {
                $this->road->refreshProgress($category);

                if ($category->fresh()->progress >= config('game.mastery.learned_at')
                    && $category->words_count >= config('game.road.min_words_to_complete')
                    && $category->status !== 'completed') {
                    $unlocked = $this->road->complete($category);
                }
            }
        }

        return [
            'correct' => $session->correct_count,
            'wrong' => $session->wrong_count,
            'total' => $session->answered_count,
            'accuracy' => $accuracy,
            'is_exam' => $category?->type === 'exam',
            'exam_passed' => $examPassed,
            'pass_mark' => config('game.exam.pass_mark'),
            'streak_days' => $request->user()->fresh()->streak_days,
            'category_progress' => $category?->fresh()->progress,
            'unlocked_position' => $unlocked?->position,
        ];
    }

    /**
     * The client reports how each pair went: [{"word_id": 4, "correct": true}].
     * Anything not reported is treated as unanswered and ignored.
     */
    protected function recordMatchPairs(TestSession $session, array $question, mixed $given, int $responseMs): void
    {
        if (! is_array($given)) {
            return;
        }

        $allowed = collect($question['pairs'] ?? [])->pluck('word_id')->flip();
        $count = max(count($given), 1);

        foreach ($given as $pair) {
            $wordId = $pair['word_id'] ?? null;

            if ($wordId === null || ! $allowed->has($wordId)) {
                continue;   // not part of this round — ignore it
            }

            $this->mastery->record(
                $session,
                ['type' => 'match', 'word_id' => $wordId],
                null,
                (bool) ($pair['correct'] ?? false),
                (int) round($responseMs / $count),
            );
        }
    }

    /**
     * "All" replays the category; "wrong" drills only the words that are still
     * below the learned threshold.
     */
    protected function wordsFor(Request $request, Category $category, string $scope)
    {
        $words = $category->words()->get();

        if ($scope !== 'wrong') {
            return $words;
        }

        $weak = WordProgress::where('user_id', $request->user()->id)
            ->whereIn('word_id', $words->pluck('id'))
            ->where('overall', '>=', config('game.mastery.learned_at'))
            ->pluck('word_id')
            ->flip();

        return $words->reject(fn (Word $w) => $weak->has($w->id))->values();
    }

    protected function authorizeOwner(Request $request, Category $category): void
    {
        abort_unless($category->user_id === $request->user()->id, Response::HTTP_FORBIDDEN);
        abort_if($category->status === 'locked', Response::HTTP_FORBIDDEN, 'Avval oldingi bosqichlarni tugating.');
    }

    protected function authorizeSession(Request $request, TestSession $session): void
    {
        abort_unless($session->user_id === $request->user()->id, Response::HTTP_FORBIDDEN);
    }
}
