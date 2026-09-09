<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Duel;
use App\Models\TestSession;
use App\Models\User;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DuelTest extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'test-token:AAA';

    protected int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('telegram.token', self::TOKEN);
        config()->set('telegram.dev_user_id', null);

        foreach (range(1, 12) as $i) {
            Word::create([
                'word' => "word{$i}",
                'part_of_speech' => 'noun',
                'translations' => ['uz' => ["soz{$i}"]],
                'frequency_rank' => $i,
            ]);
        }

        // The host onboards and fills their first stage by hand — a stage
        // starts empty, and a duel needs a playable one.
        $this->as(111)->postJson('/api/onboarding', $this->answers());
        $nodes = $this->as(111)->getJson('/api/road')->json('nodes');
        $this->categoryId = $nodes[0]['id'];
        foreach (Word::orderBy('id')->take(6)->pluck('id') as $id) {
            $this->as(111)->postJson("/api/categories/{$this->categoryId}/words", ['word_id' => $id]);
        }
    }

    protected function answers(): array
    {
        return [
            'native_lang' => 'uz', 'study_days' => ['Du'], 'reminder_at' => '19:00',
            'cefr_level' => 'A1', 'daily_goal' => 5,
        ];
    }

    /** Signs initData for a given Telegram id. */
    protected function as(int $telegramId, string $name = 'Player'): self
    {
        $params = [
            'auth_date' => (string) time(),
            'user' => json_encode(['id' => $telegramId, 'first_name' => $name, 'language_code' => 'uz']),
        ];

        ksort($params);
        $check = collect($params)->map(fn ($v, $k) => "{$k}={$v}")->implode("\n");
        $secret = hash_hmac('sha256', self::TOKEN, 'WebAppData', true);
        $params['hash'] = hash_hmac('sha256', $check, $secret);

        return $this->withHeader('X-Telegram-Init-Data', http_build_query($params));
    }

    public function test_a_host_can_open_a_lobby(): void
    {
        $response = $this->as(111)
            ->postJson("/api/categories/{$this->categoryId}/duels", ['types' => ['uz2en']])
            ->assertSuccessful();

        $response->assertJsonPath('duel.status', 'waiting')
            ->assertJsonPath('duel.is_host', true)
            ->assertJsonPath('duel.rival', null);

        $this->assertMatchesRegularExpression('/^[A-Z0-9]{6}$/', $response->json('duel.code'));
        $this->assertStringContainsString('startapp=duel_', $response->json('duel.invite_link'));
        $this->assertMatchesRegularExpression('#^https://t\.me/[A-Za-z0-9_]+\?startapp=duel_#', $response->json('duel.invite_link'));
    }

    public function test_a_friend_joins_and_both_get_the_same_questions(): void
    {
        $code = $this->as(111)
            ->postJson("/api/categories/{$this->categoryId}/duels", ['types' => ['uz2en']])
            ->json('duel.code');

        $this->as(222, 'Aziz')->postJson("/api/duels/{$code}/join")
            ->assertSuccessful()
            ->assertJsonPath('duel.status', 'ready')
            ->assertJsonPath('duel.is_host', false)
            ->assertJsonPath('duel.rival.name', 'Player');

        $host = $this->as(111)->postJson("/api/duels/{$code}/play")->assertSuccessful();
        $guest = $this->as(222)->postJson("/api/duels/{$code}/play")->assertSuccessful();

        $hostWords = collect($host->json('questions'))->pluck('word_id')->sort()->values();
        $guestWords = collect($guest->json('questions'))->pluck('word_id')->sort()->values();

        $this->assertSame($hostWords->all(), $guestWords->all(), 'both sides must face the same words');
        $this->assertNotSame($host->json('session_id'), $guest->json('session_id'));
    }

    public function test_the_higher_score_wins_and_earns_coins(): void
    {
        $code = $this->as(111)
            ->postJson("/api/categories/{$this->categoryId}/duels", ['types' => ['uz2en']])
            ->json('duel.code');

        $this->as(222, 'Aziz')->postJson("/api/duels/{$code}/join");
        $play = $this->as(111)->postJson("/api/duels/{$code}/play");
        $this->as(222)->postJson("/api/duels/{$code}/play");

        // The score is what the session recorded, not what the client claims:
        // the host gets one question right, the guest none.
        $question = $play->json('questions.0');
        $answer = collect(TestSession::find($play->json('session_id'))->payload)->firstWhere('id', $question['id'])['answer'];
        $this->as(111)->postJson("/api/tests/{$play->json('session_id')}/answer", [
            'question_id' => $question['id'], 'answer' => $answer,
        ]);

        $this->as(111)->postJson("/api/duels/{$code}/finish", ['score' => 5, 'duration_ms' => 40000])
            ->assertSuccessful()
            ->assertJsonPath('duel.status', 'playing');   // still waiting for the rival

        $this->as(222)->postJson("/api/duels/{$code}/finish", ['score' => 3, 'duration_ms' => 38000])
            ->assertSuccessful()
            ->assertJsonPath('duel.status', 'finished')
            ->assertJsonPath('duel.winner', 'rival');     // the guest lost

        $host = User::where('telegram_id', 111)->first();
        $this->assertSame(config('game.coins.per_duel_win'), $host->coins);
    }

    public function test_a_tie_on_score_is_broken_by_time(): void
    {
        $code = $this->as(111)
            ->postJson("/api/categories/{$this->categoryId}/duels", ['types' => ['uz2en']])
            ->json('duel.code');

        $this->as(222, 'Aziz')->postJson("/api/duels/{$code}/join");
        $this->as(111)->postJson("/api/duels/{$code}/finish", ['score' => 4, 'duration_ms' => 50000]);
        $this->as(222)->postJson("/api/duels/{$code}/finish", ['score' => 4, 'duration_ms' => 30000])
            ->assertJsonPath('duel.winner', 'me');        // the guest was faster
    }

    public function test_a_third_player_cannot_take_the_seat(): void
    {
        $code = $this->as(111)
            ->postJson("/api/categories/{$this->categoryId}/duels", ['types' => ['uz2en']])
            ->json('duel.code');

        $this->as(222, 'Aziz')->postJson("/api/duels/{$code}/join")->assertSuccessful();
        $this->as(333, 'Bek')->postJson("/api/duels/{$code}/join")->assertStatus(409);
        $this->as(333, 'Bek')->getJson("/api/duels/{$code}")->assertStatus(403);
    }

    public function test_you_cannot_duel_yourself(): void
    {
        $code = $this->as(111)
            ->postJson("/api/categories/{$this->categoryId}/duels", ['types' => ['uz2en']])
            ->json('duel.code');

        $this->as(111)->postJson("/api/duels/{$code}/join")->assertStatus(409);
    }

    public function test_playing_before_a_rival_joins_is_refused(): void
    {
        $code = $this->as(111)
            ->postJson("/api/categories/{$this->categoryId}/duels", ['types' => ['uz2en']])
            ->json('duel.code');

        $this->as(111)->postJson("/api/duels/{$code}/play")->assertStatus(409);
    }

    public function test_a_duel_pays_for_the_win_and_not_per_question(): void
    {
        $code = $this->as(111)
            ->postJson("/api/categories/{$this->categoryId}/duels", ['types' => ['uz2en']])
            ->json('duel.code');

        $this->as(222, 'Aziz')->postJson("/api/duels/{$code}/join");
        $play = $this->as(111)->postJson("/api/duels/{$code}/play")->assertSuccessful();
        $this->as(222)->postJson("/api/duels/{$code}/play");

        $sessionId = $play->json('session_id');
        $question = $play->json('questions.0');
        $answer = collect(TestSession::find($sessionId)->payload)->firstWhere('id', $question['id'])['answer'];

        // A right answer in a duel moves mastery but leaves the purse alone.
        $this->as(111)->postJson("/api/tests/{$sessionId}/answer", [
            'question_id' => $question['id'], 'answer' => $answer,
        ])->assertSuccessful()->assertJsonPath('correct', true)->assertJsonPath('coins_earned', 0);

        $this->assertSame(0, User::where('telegram_id', 111)->value('coins'));

        $this->as(111)->postJson("/api/duels/{$code}/finish", ['score' => 1, 'duration_ms' => 20000]);
        $this->as(222)->postJson("/api/duels/{$code}/finish", ['score' => 0, 'duration_ms' => 25000])
            ->assertJsonPath('duel.status', 'finished');

        $this->assertSame(1, config('game.coins.per_duel_win'));
        $this->assertSame(1, User::where('telegram_id', 111)->value('coins'));
    }
}
