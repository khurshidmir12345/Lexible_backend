<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeachingTest extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'test-token:AAA';

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

    protected function as(int $telegramId, string $name = 'User'): self
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

    /** A teacher with one path, two stages, and a group. */
    protected function teacherWithClass(): array
    {
        $this->as(700, 'Anvar')->postJson('/api/me/role', ['role' => 'teacher'])->assertSuccessful();

        $pathId = $this->as(700)->postJson('/api/teacher/paths', ['title' => '5-sinf'])->json('path.id');

        $first = $this->as(700)->postJson("/api/teacher/paths/{$pathId}/stages", ['title' => 'Salomlashish'])->json('stage.id');
        $second = $this->as(700)->postJson("/api/teacher/paths/{$pathId}/stages", ['title' => 'Oila'])->json('stage.id');

        $this->as(700)->patchJson("/api/teacher/stages/{$first}", [
            'title' => 'Salomlashish',
            'words' => [
                ['en' => 'hello', 'translation' => 'salom'],
                ['en' => 'goodbye', 'translation' => 'xayr'],
            ],
        ])->assertSuccessful();

        $groupId = $this->as(700)->postJson('/api/teacher/groups', [
            'title' => '5-A sinf',
            'subtitle' => '5-sinf Ingliz tili',
            'badge' => '5A',
            'path_id' => $pathId,
        ])->json('group.id');

        return ['path' => $pathId, 'stage' => $first, 'stage2' => $second, 'group' => $groupId];
    }

    public function test_a_player_can_become_a_teacher(): void
    {
        $this->as(700, 'Anvar')->postJson('/api/me/role', ['role' => 'teacher'])
            ->assertSuccessful()
            ->assertJsonPath('user.role', 'teacher');
    }

    public function test_a_teacher_skips_the_learner_questionnaire(): void
    {
        $this->as(700, 'Anvar')->postJson('/api/me/role', ['role' => 'teacher'])
            ->assertJsonPath('user.onboarded', true);

        // A student still has to answer the questions.
        $this->as(801, 'Dilnoza')->postJson('/api/me/role', ['role' => 'student'])
            ->assertJsonPath('user.onboarded', false);
    }

    public function test_students_are_kept_out_of_the_teacher_area(): void
    {
        $this->as(800, 'Dilnoza')->getJson('/api/me');

        $this->as(800)->getJson('/api/teacher/dashboard')->assertStatus(403);
        $this->as(800)->getJson('/api/teacher/groups')->assertStatus(403);
    }

    public function test_a_group_gets_a_readable_join_code(): void
    {
        $class = $this->teacherWithClass();

        $group = Group::find($class['group']);

        $this->assertMatchesRegularExpression('/^5A-[A-Z]+$/', $group->code);
    }

    public function test_a_teacher_types_words_and_they_reach_the_dictionary(): void
    {
        $class = $this->teacherWithClass();

        $stage = $this->as(700)->getJson("/api/teacher/stages/{$class['stage']}")
            ->assertSuccessful()
            ->assertJsonCount(2, 'stage.words');

        $this->assertSame('hello', $stage->json('stage.words.0.en'));
        $this->assertSame('salom', $stage->json('stage.words.0.translation'));

        // A word the dictionary did not have is created from what was typed.
        $this->assertDatabaseHas('words', ['normalized' => 'goodbye']);
    }

    public function test_joining_needs_approval_before_stages_appear(): void
    {
        $class = $this->teacherWithClass();
        $code = Group::find($class['group'])->code;

        $this->as(800, 'Dilnoza')->postJson('/api/onboarding', [
            'native_lang' => 'uz', 'study_days' => ['Du'], 'reminder_at' => '19:00',
            'cefr_level' => 'A1', 'daily_goal' => 5,
        ]);

        $this->as(800)->postJson('/api/groups/join', ['code' => $code])
            ->assertSuccessful()
            ->assertJsonPath('status', 'pending');

        $student = User::where('telegram_id', 800)->first();
        $this->assertSame(0, Category::where('user_id', $student->id)->whereNotNull('group_id')->count());

        $memberId = GroupMember::where('user_id', $student->id)->first()->id;
        $this->as(700)->postJson("/api/teacher/members/{$memberId}/approve")->assertSuccessful();

        // Approval copies the path's stages onto the student's own map.
        $stages = Category::where('user_id', $student->id)->whereNotNull('group_id')->get();
        $this->assertCount(2, $stages);
        $this->assertSame('Salomlashish', $stages->first()->title);
        $this->assertSame(2, $stages->first()->words_count);
        $this->assertSame('in_progress', $stages->first()->status);
        $this->assertSame('locked', $stages->last()->status);
    }

    public function test_an_unknown_code_is_rejected(): void
    {
        $this->as(800, 'Dilnoza')->postJson('/api/groups/join', ['code' => 'YOQ-KOD'])
            ->assertStatus(404);
    }

    public function test_a_teacher_cannot_join_their_own_group(): void
    {
        $class = $this->teacherWithClass();
        $code = Group::find($class['group'])->code;

        $this->as(700)->postJson('/api/groups/join', ['code' => $code])->assertStatus(409);
    }

    public function test_removing_a_student_takes_the_group_stages_away(): void
    {
        $class = $this->teacherWithClass();
        $code = Group::find($class['group'])->code;

        $this->as(800, 'Dilnoza')->postJson('/api/groups/join', ['code' => $code]);
        $student = User::where('telegram_id', 800)->first();
        $member = GroupMember::where('user_id', $student->id)->first();

        $this->as(700)->postJson("/api/teacher/members/{$member->id}/approve");
        $this->assertSame(2, Category::where('user_id', $student->id)->whereNotNull('group_id')->count());

        $this->as(700)->deleteJson("/api/teacher/members/{$member->id}")->assertSuccessful();
        $this->assertSame(0, Category::where('user_id', $student->id)->whereNotNull('group_id')->count());
    }

    public function test_the_group_screen_lists_pending_requests_and_a_leaderboard(): void
    {
        $class = $this->teacherWithClass();
        $code = Group::find($class['group'])->code;

        $this->as(800, 'Dilnoza')->postJson('/api/groups/join', ['code' => $code]);
        $this->as(801, 'Sardor')->postJson('/api/groups/join', ['code' => $code]);

        $response = $this->as(700)->getJson("/api/teacher/groups/{$class['group']}")->assertSuccessful();

        $response->assertJsonCount(2, 'pending')
            ->assertJsonPath('group.code', $code)
            ->assertJsonPath('group.path.title', '5-sinf')
            ->assertJsonCount(0, 'leaderboard');

        $member = GroupMember::where('status', 'pending')->first();
        $this->as(700)->postJson("/api/teacher/members/{$member->id}/approve");

        $this->as(700)->getJson("/api/teacher/groups/{$class['group']}")
            ->assertJsonCount(1, 'pending')
            ->assertJsonCount(1, 'leaderboard')
            ->assertJsonPath('leaderboard.0.rank', 1);
    }

    public function test_a_stage_cannot_hold_more_than_the_word_limit(): void
    {
        $class = $this->teacherWithClass();
        $tooMany = collect(range(1, 25))
            ->map(fn ($i) => ['en' => "extra{$i}", 'translation' => "tarjima{$i}"])
            ->all();

        $this->as(700)->patchJson("/api/teacher/stages/{$class['stage']}", ['words' => $tooMany])
            ->assertStatus(422);
    }

    public function test_the_teacher_dashboard_counts_its_own_students(): void
    {
        $class = $this->teacherWithClass();
        $code = Group::find($class['group'])->code;

        $this->as(800, 'Dilnoza')->postJson('/api/groups/join', ['code' => $code]);
        $member = GroupMember::where('status', 'pending')->first();
        $this->as(700)->postJson("/api/teacher/members/{$member->id}/approve");

        $this->as(700)->getJson('/api/teacher/dashboard')
            ->assertSuccessful()
            ->assertJsonPath('students', 1)
            ->assertJsonPath('groups', 1)
            ->assertJsonPath('pending', 0);
    }
}
