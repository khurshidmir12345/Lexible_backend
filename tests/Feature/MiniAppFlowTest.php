<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MiniAppFlowTest extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'test-token:AAA';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('telegram.token', self::TOKEN);
        config()->set('telegram.dev_user_id', null);
    }

    /** Builds an initData string signed the way Telegram signs it. */
    protected function initData(array $overrides = []): string
    {
        $params = array_merge([
            'auth_date' => (string) time(),
            'query_id' => 'AAF',
            'user' => json_encode([
                'id' => 555000111,
                'first_name' => 'Dilnoza',
                'username' => 'dilnoza',
                'language_code' => 'uz',
            ]),
        ], $overrides);

        ksort($params);
        $checkString = collect($params)->map(fn ($v, $k) => "{$k}={$v}")->implode("\n");
        $secret = hash_hmac('sha256', self::TOKEN, 'WebAppData', true);
        $params['hash'] = hash_hmac('sha256', $checkString, $secret);

        return http_build_query($params);
    }

    protected function api(string $method, string $uri, array $data = [])
    {
        return $this->withHeader('X-Telegram-Init-Data', $this->initData())
            ->json($method, $uri, $data);
    }

    public function test_it_rejects_a_request_without_init_data(): void
    {
        $this->getJson('/api/me')->assertStatus(401);
    }

    public function test_it_rejects_a_tampered_signature(): void
    {
        $tampered = $this->initData().'&extra=1';

        $this->withHeader('X-Telegram-Init-Data', $tampered)
            ->getJson('/api/me')
            ->assertStatus(401);
    }

    public function test_opening_the_app_creates_the_player(): void
    {
        $this->assertDatabaseCount('users', 0);

        $this->api('GET', '/api/me')
            ->assertSuccessful()
            ->assertJsonPath('user.name', 'Dilnoza')
            ->assertJsonPath('user.onboarded', false);

        $this->assertDatabaseHas('users', ['telegram_id' => 555000111]);
    }

    public function test_onboarding_saves_answers_and_builds_the_road(): void
    {
        $this->api('POST', '/api/onboarding', [
            'native_lang' => 'uz',
            'study_days' => ['Du', 'Se', 'Cho'],
            'reminder_at' => '19:00',
            'cefr_level' => 'A2',
            'daily_goal' => 10,
        ])->assertSuccessful()->assertJsonPath('user.onboarded', true);

        $road = $this->api('GET', '/api/road')->assertSuccessful();

        $road->assertJsonPath('nodes.0.position', 1)
            ->assertJsonPath('nodes.0.status', 'in_progress')
            ->assertJsonPath('nodes.1.status', 'locked')
            ->assertJsonPath('nodes.3.type', 'exam');

        $this->assertCount(config('game.road.initial_nodes'), $road->json('nodes'));
    }

    public function test_a_player_cannot_open_another_players_category(): void
    {
        $stranger = User::create(['telegram_id' => 999, 'first_name' => 'Someone']);
        $theirs = Category::create([
            'user_id' => $stranger->id,
            'position' => 1,
            'status' => 'in_progress',
        ]);

        $this->api('GET', "/api/categories/{$theirs->id}")->assertStatus(403);
    }

    public function test_the_full_round_from_naming_a_category_to_finishing_a_test(): void
    {
        $this->api('POST', '/api/onboarding', [
            'native_lang' => 'uz',
            'study_days' => ['Du'],
            'reminder_at' => '19:00',
            'cefr_level' => 'A1',
            'daily_goal' => 5,
        ]);

        $words = collect([
            ['word' => 'book', 'uz' => 'kitob', 'emoji' => '📚'],
            ['word' => 'water', 'uz' => 'suv', 'emoji' => '💧'],
            ['word' => 'apple', 'uz' => 'olma', 'emoji' => '🍎'],
            ['word' => 'house', 'uz' => 'uy', 'emoji' => '🏠'],
            ['word' => 'car', 'uz' => 'mashina', 'emoji' => '🚗'],
        ])->map(fn ($w) => Word::create([
            'word' => $w['word'],
            'part_of_speech' => 'noun',
            'translations' => ['uz' => [$w['uz']]],
            'emoji' => $w['emoji'],
        ]));

        $categoryId = $this->api('GET', '/api/road')->json('nodes.0.id');

        $this->api('PATCH', "/api/categories/{$categoryId}", ['title' => 'Uy jihozlari'])
            ->assertSuccessful()
            ->assertJsonPath('category.title', 'Uy jihozlari');

        foreach ($words as $word) {
            $this->api('POST', "/api/categories/{$categoryId}/words", ['word_id' => $word->id])
                ->assertSuccessful();
        }

        $this->api('GET', "/api/categories/{$categoryId}")
            ->assertSuccessful()
            ->assertJsonCount(5, 'words')
            ->assertJsonPath('words.0.overall', 0);

        $start = $this->api('POST', "/api/categories/{$categoryId}/tests", [
            'types' => ['uz2en', 'spell'],
            'scope' => 'all',
        ])->assertSuccessful();

        $sessionId = $start->json('session_id');
        $questions = $start->json('questions');

        $this->assertCount(10, $questions, 'five words across two exercises');

        // The answer must never be sent to the client.
        foreach ($questions as $question) {
            $this->assertArrayNotHasKey('answer', $question);

            if ($question['type'] === 'uz2en') {
                $this->assertArrayNotHasKey('en', $question, 'the English word is the answer here');
            }
        }

        $answered = 0;

        foreach ($questions as $question) {
            $correct = Word::whereKey($question['word_id'])->value('word');

            $this->api('POST', "/api/tests/{$sessionId}/answer", [
                'question_id' => $question['id'],
                'answer' => $correct,
                'response_ms' => 1200,
            ])->assertSuccessful()->assertJsonPath('correct', true);

            $answered++;
        }

        $this->assertSame(10, $answered);

        $finish = $this->api('POST', "/api/tests/{$sessionId}/finish", ['duration_ms' => 30000])
            ->assertSuccessful();

        $finish->assertJsonPath('correct', 10)
            ->assertJsonPath('wrong', 0)
            ->assertJsonPath('accuracy', 100)
            ->assertJsonPath('streak_days', 1);

        // Each word was asked once per exercise, so those two dimensions sit at
        // one correct answer (20) and the other four are untouched — an overall
        // average of 7, far below the threshold that completes a node.
        $this->api('GET', "/api/categories/{$categoryId}")
            ->assertJsonPath('mastery_by_type.uz2en', 20)
            ->assertJsonPath('mastery_by_type.spell', 20)
            ->assertJsonPath('mastery_by_type.card', 0)
            ->assertJsonPath('words.0.overall', 7);

        // The node stays open: mastery is nowhere near the learned threshold.
        $this->assertSame('in_progress', Category::find($categoryId)->status);
    }

    public function test_a_matching_round_records_mastery_for_every_pair(): void
    {
        $this->api('POST', '/api/onboarding', [
            'native_lang' => 'uz', 'study_days' => ['Du'], 'reminder_at' => '19:00',
            'cefr_level' => 'A1', 'daily_goal' => 5,
        ]);

        foreach ([['book', 'kitob'], ['water', 'suv'], ['apple', 'olma'], ['house', 'uy']] as [$en, $uz]) {
            Word::create(['word' => $en, 'part_of_speech' => 'noun', 'translations' => ['uz' => [$uz]]]);
        }

        $categoryId = $this->api('GET', '/api/road')->json('nodes.0.id');

        foreach (Word::pluck('id') as $id) {
            $this->api('POST', "/api/categories/{$categoryId}/words", ['word_id' => $id]);
        }

        $start = $this->api('POST', "/api/categories/{$categoryId}/tests", ['types' => ['match']]);
        $question = $start->json('questions.0');

        $this->assertSame('match', $question['type']);
        $this->assertCount(4, $question['pairs']);

        // Three pairs found cleanly, one fumbled.
        $pairs = collect($question['pairs'])
            ->map(fn ($pair, $index) => ['word_id' => $pair['word_id'], 'correct' => $index !== 0])
            ->values()
            ->all();

        $this->api('POST', "/api/tests/{$start->json('session_id')}/answer", [
            'question_id' => $question['id'],
            'answer' => $pairs,
        ])->assertSuccessful()->assertJsonPath('correct', false);   // one miss fails the round

        $mastery = $this->api('GET', "/api/categories/{$categoryId}")->json('words');

        $fumbled = collect($mastery)->firstWhere('id', $pairs[0]['word_id']);
        $clean = collect($mastery)->firstWhere('id', $pairs[1]['word_id']);

        $this->assertSame(0, $fumbled['mastery']['match'], 'a missed pair must not gain mastery');
        $this->assertSame(20, $clean['mastery']['match'], 'a found pair gains one step');
    }

    public function test_a_wrong_answer_is_graded_as_wrong(): void
    {
        $this->api('POST', '/api/onboarding', [
            'native_lang' => 'uz', 'study_days' => ['Du'], 'reminder_at' => '19:00',
            'cefr_level' => 'A1', 'daily_goal' => 5,
        ]);

        foreach ([['book', 'kitob'], ['water', 'suv'], ['apple', 'olma'], ['house', 'uy']] as [$en, $uz]) {
            Word::create(['word' => $en, 'part_of_speech' => 'noun', 'translations' => ['uz' => [$uz]]]);
        }

        $categoryId = $this->api('GET', '/api/road')->json('nodes.0.id');

        foreach (Word::pluck('id') as $id) {
            $this->api('POST', "/api/categories/{$categoryId}/words", ['word_id' => $id]);
        }

        $start = $this->api('POST', "/api/categories/{$categoryId}/tests", ['types' => ['spell']]);
        $question = $start->json('questions.0');

        $this->api('POST', "/api/tests/{$start->json('session_id')}/answer", [
            'question_id' => $question['id'],
            'answer' => 'definitely-wrong',
        ])->assertSuccessful()->assertJsonPath('correct', false);
    }
}
