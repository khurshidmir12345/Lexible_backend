<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\User;
use App\Services\Game\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'test-token:AAA';

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('telegram.token', self::TOKEN);
        config()->set('telegram.dev_user_id', null);

        $this->user = User::create(['telegram_id' => 555000111, 'first_name' => 'Dilnoza']);
    }

    protected function api(string $method, string $uri, array $data = [])
    {
        $params = [
            'auth_date' => (string) time(),
            'user' => json_encode(['id' => 555000111, 'first_name' => 'Dilnoza', 'language_code' => 'uz']),
        ];

        ksort($params);
        $check = collect($params)->map(fn ($v, $k) => "{$k}={$v}")->implode("\n");
        $secret = hash_hmac('sha256', self::TOKEN, 'WebAppData', true);
        $params['hash'] = hash_hmac('sha256', $check, $secret);

        return $this->withHeader('X-Telegram-Init-Data', http_build_query($params))->json($method, $uri, $data);
    }

    protected function notifications(): NotificationService
    {
        return app(NotificationService::class);
    }

    public function test_the_feed_is_grouped_by_day(): void
    {
        $this->notifications()->stageUnlocked($this->user, 'Taomlar');
        $this->notifications()->streak($this->user, 7);

        AppNotification::latest('id')->first()->update(['created_at' => now()->subDay()]);

        $this->notifications()->duelFinished($this->user, false, 'Aziz', 3, 5);
        AppNotification::latest('id')->first()->update(['created_at' => now()->subDays(4)]);

        $response = $this->api('GET', '/api/notifications')->assertSuccessful();

        $this->assertSame(
            ['BUGUN', 'KECHA', 'AVVALROQ'],
            collect($response->json('groups'))->pluck('label')->all(),
        );

        $response->assertJsonPath('unread', 3)
            ->assertJsonPath('groups.0.items.0.title', 'Yangi bosqich ochildi')
            ->assertJsonPath('groups.0.items.0.emoji', '🎉');
    }

    public function test_reading_the_feed_clears_the_badge(): void
    {
        $this->notifications()->streak($this->user, 3);
        $this->notifications()->streak($this->user, 7);

        $this->api('GET', '/api/notifications')->assertJsonPath('unread', 2);
        $this->api('POST', '/api/notifications/read')->assertJsonPath('unread', 0);
        $this->api('GET', '/api/notifications')->assertJsonPath('unread', 0);
    }

    public function test_a_win_and_a_loss_read_differently(): void
    {
        $this->notifications()->duelFinished($this->user, true, 'Aziz', 5, 3);
        $win = AppNotification::latest('id')->first();

        $this->notifications()->duelFinished($this->user, false, 'Aziz', 2, 6);
        $loss = AppNotification::latest('id')->first();

        $this->assertSame('Duelda gʼalaba — 5:3', $win->title);
        $this->assertSame('🏆', $win->emoji);
        $this->assertSame('Duelda magʼlubiyat — 2:6', $loss->title);
    }

    public function test_a_player_only_sees_their_own_feed(): void
    {
        $other = User::create(['telegram_id' => 999, 'first_name' => 'Someone']);
        $this->notifications()->streak($other, 30);

        $this->api('GET', '/api/notifications')
            ->assertSuccessful()
            ->assertJsonPath('unread', 0)
            ->assertJsonCount(0, 'groups');
    }
}
