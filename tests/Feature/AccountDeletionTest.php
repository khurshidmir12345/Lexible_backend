<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Models\Word;
use App\Models\WordProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'test-token:AAA';

    /** @var array<int, string> */
    protected array $names = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('telegram.token', self::TOKEN);
        config()->set('telegram.dev_user_id', null);

        foreach (range(1, 20) as $i) {
            Word::create([
                'word' => "word{$i}",
                'part_of_speech' => 'noun',
                'translations' => ['uz' => ["soz{$i}"]],
                'frequency_rank' => $i,
            ]);
        }
    }

    protected function as(int $telegramId, ?string $name = null): self
    {
        $this->names[$telegramId] = $name ?? $this->names[$telegramId] ?? 'User';

        $params = [
            'auth_date' => (string) time(),
            'user' => json_encode([
                'id' => $telegramId,
                'first_name' => $this->names[$telegramId],
                'language_code' => 'uz',
            ]),
        ];

        ksort($params);
        $check = collect($params)->map(fn ($v, $k) => "{$k}={$v}")->implode("\n");
        $secret = hash_hmac('sha256', self::TOKEN, 'WebAppData', true);
        $params['hash'] = hash_hmac('sha256', $check, $secret);

        return $this->withHeader('X-Telegram-Init-Data', http_build_query($params));
    }

    protected function onboard(int $telegramId, string $name): void
    {
        $this->as($telegramId, $name)->postJson('/api/onboarding', [
            'native_lang' => 'uz',
            'study_days' => ['Du'],
            'reminder_at' => '19:00',
            'cefr_level' => 'A1',
            'daily_goal' => 5,
        ]);
    }

    public function test_the_impact_summary_reports_what_would_be_lost(): void
    {
        $this->onboard(800, 'Dilnoza');
        User::where('telegram_id', 800)->update(['words_learned' => 12, 'coins' => 340, 'streak_days' => 4]);

        $this->as(800)->getJson('/api/me/impact')
            ->assertSuccessful()
            ->assertJsonPath('impact.role', 'student')
            ->assertJsonPath('impact.words_learned', 12)
            ->assertJsonPath('impact.coins', 340)
            ->assertJsonPath('impact.streak_days', 4)
            ->assertJsonPath('impact.groups_taught', 0);
    }

    public function test_a_teacher_is_warned_about_the_groups_that_go_with_them(): void
    {
        $this->as(700, 'Anvar')->postJson('/api/me/role', ['role' => 'teacher']);
        $this->as(700)->postJson('/api/teacher/groups', ['title' => '5-A sinf']);

        $this->as(700)->getJson('/api/me/impact')
            ->assertSuccessful()
            ->assertJsonPath('impact.role', 'teacher')
            ->assertJsonPath('impact.groups_taught', 1);
    }

    public function test_deleting_an_account_removes_the_player_and_their_progress(): void
    {
        $this->onboard(800, 'Dilnoza');

        $user = User::where('telegram_id', 800)->firstOrFail();
        $categoryId = $this->as(800)->getJson('/api/road')->json('nodes.0.id');
        $this->as(800)->getJson("/api/categories/{$categoryId}");

        $this->assertTrue(Category::where('user_id', $user->id)->exists());

        $this->as(800)->deleteJson('/api/me')
            ->assertSuccessful()
            ->assertJsonPath('deleted', true);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('categories', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('word_progress', ['user_id' => $user->id]);
    }

    public function test_leaving_keeps_the_groups_roster_count_honest(): void
    {
        $this->as(700, 'Anvar')->postJson('/api/me/role', ['role' => 'teacher']);
        $groupId = $this->as(700)->postJson('/api/teacher/groups', ['title' => '5-A sinf'])->json('group.id');
        $code = Group::find($groupId)->code;

        foreach ([[800, 'Dilnoza'], [801, 'Sardor']] as [$id, $name]) {
            $this->onboard($id, $name);
            $this->as($id)->postJson('/api/groups/join', ['code' => $code]);
            $member = GroupMember::where('group_id', $groupId)
                ->whereHas('user', fn ($q) => $q->where('telegram_id', $id))
                ->first();
            $this->as(700)->postJson("/api/teacher/members/{$member->id}/approve");
        }

        $this->assertSame(2, Group::find($groupId)->members_count);

        $this->as(800)->deleteJson('/api/me')->assertSuccessful();

        $this->assertSame(1, Group::find($groupId)->fresh()->members_count);
    }

    public function test_a_deleted_player_comes_back_as_a_brand_new_account(): void
    {
        $this->onboard(800, 'Dilnoza');
        User::where('telegram_id', 800)->update(['words_learned' => 9, 'coins' => 120]);

        $this->as(800)->deleteJson('/api/me')->assertSuccessful();

        // The same Telegram user opening the app again starts from scratch.
        $this->as(800, 'Dilnoza')->getJson('/api/me')
            ->assertSuccessful()
            ->assertJsonPath('user.onboarded', false)
            ->assertJsonPath('user.words_learned', 0)
            ->assertJsonPath('user.coins', 0);
    }
}
