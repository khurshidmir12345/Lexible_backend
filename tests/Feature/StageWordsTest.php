<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use App\Models\Word;
use App\Models\WordProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StageWordsTest extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'test-token:AAA';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('telegram.token', self::TOKEN);
        config()->set('telegram.dev_user_id', null);

        // A dictionary big enough that two stages cannot overlap by accident.
        foreach (range(1, 40) as $i) {
            Word::create([
                'word' => "word{$i}",
                'part_of_speech' => 'noun',
                'translations' => ['uz' => ["soz{$i}"]],
                'frequency_rank' => $i,
                'cefr_level' => $i <= 20 ? 'A1' : 'B1',
            ]);
        }
    }

    protected function initData(): string
    {
        $params = [
            'auth_date' => (string) time(),
            'user' => json_encode(['id' => 555000111, 'first_name' => 'Dilnoza', 'language_code' => 'uz']),
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

    protected function onboard(int $goal = 10, string $level = 'A1'): void
    {
        $this->api('POST', '/api/onboarding', [
            'native_lang' => 'uz',
            'study_days' => ['Du'],
            'reminder_at' => '19:00',
            'cefr_level' => $level,
            'daily_goal' => $goal,
        ]);
    }

    public function test_opening_a_stage_fills_it_with_the_daily_goal(): void
    {
        $this->onboard(goal: 7);

        $categoryId = $this->api('GET', '/api/road')->json('nodes.0.id');

        $this->api('GET', "/api/categories/{$categoryId}")
            ->assertSuccessful()
            ->assertJsonPath('auto_filled', 7)
            ->assertJsonCount(7, 'words');
    }

    public function test_reopening_a_stage_does_not_add_more_words(): void
    {
        $this->onboard(goal: 5);

        $categoryId = $this->api('GET', '/api/road')->json('nodes.0.id');

        $first = $this->api('GET', "/api/categories/{$categoryId}")->json('words');
        $second = $this->api('GET', "/api/categories/{$categoryId}");

        $second->assertJsonPath('auto_filled', 0)->assertJsonCount(5, 'words');

        $this->assertSame(
            collect($first)->pluck('id')->all(),
            collect($second->json('words'))->pluck('id')->all(),
        );
    }

    public function test_a_stage_prefers_words_at_the_players_level(): void
    {
        $this->onboard(goal: 5, level: 'B1');

        $categoryId = $this->api('GET', '/api/road')->json('nodes.0.id');
        $words = $this->api('GET', "/api/categories/{$categoryId}")->json('words');

        $levels = Word::whereIn('id', collect($words)->pluck('id'))->pluck('cefr_level')->unique();

        $this->assertSame(['B1'], $levels->values()->all());
    }

    public function test_a_later_stage_never_repeats_words_from_an_earlier_one(): void
    {
        $this->onboard(goal: 6);

        $user = User::where('telegram_id', 555000111)->first();
        $nodes = $this->api('GET', '/api/road')->json('nodes');

        $first = $this->api('GET', "/api/categories/{$nodes[0]['id']}")->json('words');

        // The second stage is locked until the first is done, so unlock it the
        // way finishing the first one would.
        Category::whereKey($nodes[1]['id'])->update(['status' => 'in_progress']);

        // Words only count as "seen" once they have progress, which is what a
        // played round creates.
        foreach ($first as $word) {
            WordProgress::create(['user_id' => $user->id, 'word_id' => $word['id'], 'overall' => 20]);
        }

        $second = $this->api('GET', "/api/categories/{$nodes[1]['id']}")->json('words');

        $overlap = collect($first)->pluck('id')->intersect(collect($second)->pluck('id'));

        $this->assertCount(6, $second);
        $this->assertTrue($overlap->isEmpty(), 'stages must not share words');
    }

    public function test_learned_words_are_listed_separately_from_the_ones_in_progress(): void
    {
        $this->onboard(goal: 4);

        $user = User::where('telegram_id', 555000111)->first();
        $words = Word::limit(3)->get();

        WordProgress::create(['user_id' => $user->id, 'word_id' => $words[0]->id, 'overall' => 85, 'is_learned' => true]);
        WordProgress::create(['user_id' => $user->id, 'word_id' => $words[1]->id, 'overall' => 70, 'is_learned' => true]);
        WordProgress::create(['user_id' => $user->id, 'word_id' => $words[2]->id, 'overall' => 30]);

        $this->api('GET', '/api/learned')
            ->assertSuccessful()
            ->assertJsonPath('counts.learned', 2)
            ->assertJsonPath('counts.learning', 1)
            ->assertJsonCount(2, 'words')
            ->assertJsonPath('words.0.overall', 85);

        $this->api('GET', '/api/learned?filter=learning')
            ->assertSuccessful()
            ->assertJsonCount(1, 'words')
            ->assertJsonPath('words.0.overall', 30);
    }
}
