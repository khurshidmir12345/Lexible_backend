<?php

namespace Tests\Feature;

use App\Jobs\ImportWord;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Search answers from our own table; anything missing is fetched later, off the request. */
class WordSearchTest extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'test-token:AAA';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('telegram.token', self::TOKEN);
        config()->set('telegram.dev_user_id', null);
        config()->set('dictionary.enrich_on_miss', true);

        Word::create(['word' => 'apple', 'part_of_speech' => 'noun', 'translations' => ['uz' => ['olma']], 'frequency_rank' => 1, 'is_active' => true]);
    }

    protected function api(string $uri)
    {
        $params = [
            'auth_date' => (string) time(),
            'user' => json_encode(['id' => 555000333, 'first_name' => 'Zilola', 'language_code' => 'uz']),
        ];
        ksort($params);
        $check = collect($params)->map(fn ($v, $k) => "{$k}={$v}")->implode("\n");
        $params['hash'] = hash_hmac('sha256', $check, hash_hmac('sha256', self::TOKEN, 'WebAppData', true));

        return $this->withHeader('X-Telegram-Init-Data', http_build_query($params))->getJson($uri);
    }

    public function test_a_hit_comes_from_the_table_and_nothing_is_queued(): void
    {
        Queue::fake();

        $this->api('/api/words/search?q=app')
            ->assertSuccessful()
            ->assertJsonPath('words.0.en', 'apple');

        Queue::assertNothingPushed();
    }

    public function test_a_miss_answers_at_once_and_queues_the_import(): void
    {
        Queue::fake();

        $this->api('/api/words/search?q=beautiful')
            ->assertSuccessful()
            ->assertJsonCount(0, 'words')
            ->assertJsonPath('imported', false);

        Queue::assertPushed(ImportWord::class, fn (ImportWord $job) => $job->query === 'beautiful');
    }

    public function test_a_spelling_known_to_be_missing_is_not_queued_again(): void
    {
        Queue::fake();
        Cache::put(ImportWord::missKey('brath'), true, 60);

        $this->api('/api/words/search?q=brath')->assertSuccessful();

        Queue::assertNothingPushed();
    }
}
