<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramMessage;
use App\Models\AppNotification;
use App\Models\User;
use App\Services\Game\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BotNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function student(array $extra = []): User
    {
        static $n = 0;
        $n++;

        return User::create(array_merge([
            'telegram_id' => 1000 + $n, 'chat_id' => 1000 + $n, 'first_name' => "Talaba{$n}",
            'onboarded' => true, 'role' => 'student', 'timezone' => 'Asia/Tashkent',
            'reminder_at' => '19:00', 'study_days' => ['Du', 'Se', 'Cho', 'Pa', 'Ju', 'Sha', 'Ya'],
            'daily_goal' => 10,
        ], $extra));
    }

    /** Tuesday 2026-09-08 19:07 in Tashkent (UTC+5). */
    protected function freezeAt(string $local): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse("2026-09-08 {$local}", 'Asia/Tashkent'));
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse("2026-09-08 {$local}", 'Asia/Tashkent'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_reminder_reaches_the_right_people_once(): void
    {
        Queue::fake();
        $this->freezeAt('19:07');

        $due = $this->student();
        $later = $this->student(['reminder_at' => '21:00']);
        $off = $this->student(['reminders_enabled' => false]);
        $practised = $this->student(['last_practiced_date' => '2026-09-08']);
        $notTuesday = $this->student(['study_days' => ['Du', 'Cho']]);
        $teacher = $this->student(['role' => 'teacher', 'reminder_at' => '19:00']);
        $blocked = $this->student(['has_blocked_bot' => true]);
        $stale = $this->student(['reminder_at' => '16:00']);   // more than 90 minutes ago

        $this->artisan('notify:reminders')->assertSuccessful();

        Queue::assertPushed(SendTelegramMessage::class, 1);
        Queue::assertPushed(SendTelegramMessage::class, fn ($job) => $job->userId === $due->id
            && str_contains($job->text, '10 ta soʼz')
            && $job->replyMarkup['inline_keyboard'][0][0]['style'] === 'success');

        $this->assertSame('2026-09-08', $due->fresh()->reminded_on?->toDateString());
        $this->assertSame(1, AppNotification::where('user_id', $due->id)->where('type', 'reminder')->count());

        // The sweep runs again five minutes later: nothing new goes out.
        $this->freezeAt('19:12');
        $this->artisan('notify:reminders')->assertSuccessful();
        Queue::assertPushed(SendTelegramMessage::class, 1);

        foreach ([$later, $off, $practised, $notTuesday, $teacher, $blocked, $stale] as $user) {
            $this->assertNull($user->fresh()->reminded_on, "{$user->first_name} should not be reminded");
        }
    }

    public function test_the_evening_warns_about_a_streak_that_ends_tonight(): void
    {
        Queue::fake();
        $this->freezeAt('20:30');

        $risk = $this->student(['streak_days' => 5, 'last_practiced_date' => '2026-09-07', 'reminder_at' => '08:00']);
        $safe = $this->student(['streak_days' => 5, 'last_practiced_date' => '2026-09-08', 'reminder_at' => '08:00']);
        $noStreak = $this->student(['streak_days' => 1, 'last_practiced_date' => '2026-09-07', 'reminder_at' => '08:00']);

        $this->artisan('notify:reminders')->assertSuccessful();

        Queue::assertPushed(SendTelegramMessage::class, 1);
        Queue::assertPushed(SendTelegramMessage::class, fn ($job) => $job->userId === $risk->id && str_contains($job->text, '5 kunlik'));
        $this->assertSame('2026-09-08', $risk->fresh()->streak_warned_on?->toDateString());
        $this->assertNull($safe->fresh()->streak_warned_on);
        $this->assertNull($noStreak->fresh()->streak_warned_on);
    }

    public function test_no_streak_warning_outside_the_evening_window(): void
    {
        Queue::fake();
        $this->freezeAt('15:00');
        $this->student(['streak_days' => 5, 'last_practiced_date' => '2026-09-07', 'reminder_at' => '08:00']);

        $this->artisan('notify:reminders')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_the_job_sends_and_remembers_a_blocked_bot(): void
    {
        // The client retries failed statuses once, so the 403 must answer twice.
        Http::fake([
            'api.telegram.org/*/sendMessage' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 1]])
                ->whenEmpty(Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], 403)),
        ]);
        config(['telegram.token' => '123:abc']);

        $user = $this->student();

        (new SendTelegramMessage($user->id, 'Salom', ['inline_keyboard' => []]))->handle(app(\App\Services\Telegram\TelegramClient::class));
        $this->assertFalse($user->fresh()->has_blocked_bot);

        (new SendTelegramMessage($user->id, 'Salom'))->handle(app(\App\Services\Telegram\TelegramClient::class));
        $this->assertTrue($user->fresh()->has_blocked_bot);

        Http::assertSentCount(3);
    }

    public function test_every_event_leaves_a_feed_row_and_queues_a_bot_message(): void
    {
        Queue::fake();
        $user = $this->student();
        $service = app(NotificationService::class);

        $service->pathAssigned($user, 'Nodira opa', '5A', 'Taomlar', 10);
        $service->competitionOpened($user, 'Nodira opa', '5A · Taomlar', 'ABC12');
        $service->duelJoined($user, 'Aziz', 'Taomlar');
        $service->joinedGroup($user, '5A', 'Nodira opa');

        Queue::assertPushed(SendTelegramMessage::class, 4);
        Queue::assertPushed(SendTelegramMessage::class, fn ($job) => str_contains($job->text, 'Taomlar') && str_contains($job->replyMarkup['inline_keyboard'][0][0]['web_app']['url'], 'startapp=comp_ABC12'));
        $this->assertSame(4, AppNotification::where('user_id', $user->id)->count());
        $this->assertSame(['startapp' => 'comp_ABC12'], AppNotification::where('type', 'competition')->first()->data);
    }
}
