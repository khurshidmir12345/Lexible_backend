<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Competition;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\TestSession;
use App\Models\User;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A teacher picks which games a path is played with; the class gets only those. */
class PathTypesTest extends TestCase
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

        foreach (range(1, 10) as $i) {
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
            'user' => json_encode(['id' => $telegramId, 'first_name' => $this->names[$telegramId], 'language_code' => 'uz']),
        ];

        ksort($params);
        $check = collect($params)->map(fn ($v, $k) => "{$k}={$v}")->implode("\n");
        $secret = hash_hmac('sha256', self::TOKEN, 'WebAppData', true);
        $params['hash'] = hash_hmac('sha256', $check, $secret);

        return $this->withHeader('X-Telegram-Init-Data', http_build_query($params));
    }

    /** A path limited to two games, one filled stage, a class, one approved student. */
    protected function classroom(array $types): array
    {
        $this->as(700, 'Anvar')->postJson('/api/me/role', ['role' => 'teacher']);

        $path = $this->as(700)->postJson('/api/teacher/paths', ['title' => 'IELTS', 'types' => $types])
            ->assertSuccessful()->json('path');
        $stageId = $this->as(700)->postJson("/api/teacher/paths/{$path['id']}/stages", ['title' => 'Maktab'])->json('stage.id');
        $this->as(700)->patchJson("/api/teacher/stages/{$stageId}", [
            'title' => 'Maktab',
            'words' => Word::orderBy('id')->take(6)->pluck('id')->all(),
        ])->assertSuccessful();

        $groupId = $this->as(700)->postJson('/api/teacher/groups', ['title' => '5-A', 'badge' => '5A', 'path_id' => $path['id']])->json('group.id');

        $this->as(800, 'Dilnoza')->postJson('/api/onboarding', [
            'native_lang' => 'uz', 'study_days' => ['Du'], 'reminder_at' => '19:00', 'cefr_level' => 'A1', 'daily_goal' => 5,
        ]);
        $this->as(800)->postJson('/api/groups/join', ['code' => Group::find($groupId)->code]);
        $member = GroupMember::where('group_id', $groupId)->first();
        $this->as(700)->postJson("/api/teacher/members/{$member->id}/approve");

        $student = User::where('telegram_id', 800)->first();
        $category = Category::where('user_id', $student->id)->where('path_stage_id', $stageId)->firstOrFail();

        return ['path' => $path, 'stage' => $stageId, 'group' => $groupId, 'category' => $category];
    }

    public function test_the_path_remembers_the_games_the_teacher_chose(): void
    {
        $class = $this->classroom(['uz2en', 'spell']);

        $this->assertSame(['uz2en', 'spell'], $class['path']['types']);

        $listed = collect($this->as(700)->getJson('/api/teacher/paths')->json('paths'))->firstWhere('id', $class['path']['id']);
        $this->assertSame(['uz2en', 'spell'], $listed['types']);

        $this->as(700)->patchJson("/api/teacher/paths/{$class['path']['id']}", ['types' => ['match']])
            ->assertSuccessful()
            ->assertJsonPath('path.types', ['match']);
    }

    public function test_a_path_with_nothing_chosen_offers_every_game(): void
    {
        $this->as(700, 'Anvar')->postJson('/api/me/role', ['role' => 'teacher']);

        $this->as(700)->postJson('/api/teacher/paths', ['title' => 'Hammasi'])
            ->assertSuccessful()
            ->assertJsonPath('path.types', config('game.test_types'));
    }

    public function test_the_student_sees_and_plays_only_the_chosen_games(): void
    {
        $class = $this->classroom(['uz2en', 'spell']);
        $id = $class['category']->id;

        $this->as(800)->getJson("/api/categories/{$id}")
            ->assertJsonPath('category.types', ['uz2en', 'spell']);

        // A game the teacher switched off is refused outright…
        $this->as(800)->postJson("/api/categories/{$id}/tests", ['types' => ['image']])->assertStatus(422);

        // …and a mixed request is trimmed to what the path allows.
        $sessionId = $this->as(800)->postJson("/api/categories/{$id}/tests", ['types' => ['image', 'spell']])
            ->assertSuccessful()
            ->json('session_id');

        $this->assertSame(['spell'], TestSession::find($sessionId)->types);
    }

    public function test_a_class_game_is_played_with_the_paths_games_minus_the_flashcard(): void
    {
        $class = $this->classroom(['card', 'en2uz', 'match']);

        $code = $this->as(700)
            ->postJson("/api/teacher/groups/{$class['group']}/competitions", ['path_stage_id' => $class['stage']])
            ->assertSuccessful()
            ->json('competition.code');

        $this->assertSame(['en2uz', 'match'], Competition::where('code', $code)->first()->types);
    }

    public function test_the_players_own_stage_is_never_limited(): void
    {
        $this->as(800, 'Dilnoza')->postJson('/api/onboarding', [
            'native_lang' => 'uz', 'study_days' => ['Du'], 'reminder_at' => '19:00', 'cefr_level' => 'A1', 'daily_goal' => 5,
        ]);
        $id = $this->as(800)->getJson('/api/road')->json('nodes.0.id');

        $this->as(800)->getJson("/api/categories/{$id}")
            ->assertJsonPath('category.types', config('game.test_types'));
    }
}
