<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\TestSession;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamTest extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'test-token:AAA';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('telegram.token', self::TOKEN);
        config()->set('telegram.dev_user_id', null);

        foreach (range(1, 60) as $i) {
            Word::create([
                'word' => "word{$i}",
                'part_of_speech' => 'noun',
                'translations' => ['uz' => ["soz{$i}"]],
                'frequency_rank' => $i,
                'cefr_level' => 'A1',
            ]);
        }
    }

    protected function initData(): string
    {
        $params = [
            'auth_date' => (string) time(),
            'user' => json_encode(['id' => 555000222, 'first_name' => 'Sardor', 'language_code' => 'uz']),
        ];

        ksort($params);
        $checkString = collect($params)->map(fn ($v, $k) => "{$k}={$v}")->implode("\n");
        $secret = hash_hmac('sha256', self::TOKEN, 'WebAppData', true);
        $params['hash'] = hash_hmac('sha256', $checkString, $secret);

        return http_build_query($params);
    }

    protected function api(string $method, string $uri, array $data = [])
    {
        return $this->withHeader('X-Telegram-Init-Data', $this->initData())->json($method, $uri, $data);
    }

    protected function onboard(int $goal = 5): void
    {
        $this->api('POST', '/api/onboarding', [
            'native_lang' => 'uz',
            'study_days' => ['Du'],
            'reminder_at' => '19:00',
            'cefr_level' => 'A1',
            'daily_goal' => $goal,
        ]);
    }

    /** Fill every normal stage before the first exam so the exam has a pool. */
    protected function fillStagesBeforeExam(): Category
    {
        $this->onboard();

        $nodes = collect($this->api('GET', '/api/road')->json('nodes'));
        $exam = $nodes->firstWhere('type', 'exam');

        foreach ($nodes->where('type', 'normal')->where('position', '<', $exam['position']) as $node) {
            // Opening the stage auto-fills it; the round itself is not what is
            // under test here, so the node is closed directly.
            $this->api('GET', "/api/categories/{$node['id']}");
            Category::where('id', $node['id'])->update(['status' => 'completed', 'progress' => 100]);
        }

        $exam = Category::find($exam['id']);
        $exam->update(['status' => 'in_progress']);

        return $exam;
    }

    public function test_the_road_contains_an_exam_checkpoint(): void
    {
        $this->onboard();

        $types = collect($this->api('GET', '/api/road')->json('nodes'))->pluck('type');

        $this->assertContains('exam', $types->all());
    }

    public function test_the_briefing_reports_the_question_count_and_pass_mark(): void
    {
        $exam = $this->fillStagesBeforeExam();

        $this->api('GET', "/api/categories/{$exam->id}/exam")
            ->assertSuccessful()
            ->assertJsonPath('exam.pass_mark', config('game.exam.pass_mark'))
            ->assertJsonPath('exam.questions', config('game.exam.questions'))
            ->assertJsonPath('exam.ready', true);
    }

    public function test_the_briefing_is_not_ready_when_no_stage_precedes_it(): void
    {
        $this->onboard();

        // Nothing opened yet, so the earlier stages hold no words at all.
        $exam = Category::where('type', 'exam')->orderBy('position')->first();
        $exam->update(['status' => 'in_progress']);

        $this->api('GET', "/api/categories/{$exam->id}/exam")
            ->assertSuccessful()
            ->assertJsonPath('exam.ready', false)
            ->assertJsonPath('exam.pool', 0);
    }

    public function test_the_briefing_rejects_a_normal_stage(): void
    {
        $this->onboard();

        $node = collect($this->api('GET', '/api/road')->json('nodes'))->firstWhere('type', 'normal');

        $this->api('GET', "/api/categories/{$node['id']}/exam")->assertStatus(422);
    }

    public function test_an_exam_draws_its_questions_from_the_earlier_stages(): void
    {
        $exam = $this->fillStagesBeforeExam();

        $earlier = Category::where('user_id', $exam->user_id)
            ->where('type', 'normal')
            ->where('position', '<', $exam->position)
            ->pluck('id');

        $allowed = Word::whereHas('categories', fn ($q) => $q->whereIn('categories.id', $earlier))
            ->pluck('id');

        $response = $this->api('POST', "/api/categories/{$exam->id}/tests", ['types' => []])
            ->assertSuccessful();

        $session = TestSession::find($response->json('session_id'));

        $this->assertNotEmpty($session->payload);
        $this->assertCount(config('game.exam.questions'), $session->payload);

        foreach ($session->payload as $question) {
            $this->assertContains($question['word_id'], $allowed->all());
        }
    }

    public function test_passing_an_exam_completes_the_node_and_unlocks_the_next(): void
    {
        $exam = $this->fillStagesBeforeExam();

        $sessionId = $this->api('POST', "/api/categories/{$exam->id}/tests", ['types' => []])
            ->json('session_id');

        $session = TestSession::find($sessionId);

        foreach ($session->payload as $question) {
            $this->api('POST', "/api/tests/{$sessionId}/answer", [
                'question_id' => $question['id'],
                'answer' => $question['answer'],
            ]);
        }

        $this->api('POST', "/api/tests/{$sessionId}/finish", ['duration_ms' => 40000])
            ->assertSuccessful()
            ->assertJsonPath('is_exam', true)
            ->assertJsonPath('exam_passed', true)
            ->assertJsonPath('accuracy', 100);

        $this->assertSame('completed', $exam->fresh()->status);

        $next = Category::where('user_id', $exam->user_id)
            ->where('position', $exam->position + 1)
            ->first();

        $this->assertNotSame('locked', $next->status);
    }

    public function test_failing_an_exam_leaves_the_node_closed(): void
    {
        $exam = $this->fillStagesBeforeExam();

        $sessionId = $this->api('POST', "/api/categories/{$exam->id}/tests", ['types' => []])
            ->json('session_id');

        $session = TestSession::find($sessionId);

        foreach ($session->payload as $question) {
            $this->api('POST', "/api/tests/{$sessionId}/answer", [
                'question_id' => $question['id'],
                'answer' => '__notaword__',
            ]);
        }

        $this->api('POST', "/api/tests/{$sessionId}/finish", ['duration_ms' => 40000])
            ->assertSuccessful()
            ->assertJsonPath('is_exam', true)
            ->assertJsonPath('exam_passed', false);

        $this->assertNotSame('completed', $exam->fresh()->status);
    }
}
