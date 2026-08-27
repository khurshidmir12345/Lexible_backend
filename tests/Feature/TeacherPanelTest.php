<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\PathStage;
use App\Models\User;
use App\Models\Word;
use App\Models\WordProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The screens added for the UT-* artboards: the group road (UT-04b), a
 * stage's results (UT-05), open games (UT-MD2), adding a student by ID
 * (UT-MD1), plans (UT-08) and the profile numbers (UT-09).
 */
class TeacherPanelTest extends TestCase
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

        foreach (range(1, 12) as $i) {
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

    /** A teacher, a two-stage path, a class, and one approved student. */
    protected function classroom(): array
    {
        $this->as(700, 'Anvar')->postJson('/api/me/role', ['role' => 'teacher'])->assertSuccessful();

        $pathId = $this->as(700)->postJson('/api/teacher/paths', [
            'title' => '5-sinf', 'subtitle' => 'Ingliz tili',
        ])->json('path.id');

        $one = $this->as(700)->postJson("/api/teacher/paths/{$pathId}/stages", ['title' => 'Salomlashish'])->json('stage.id');
        $two = $this->as(700)->postJson("/api/teacher/paths/{$pathId}/stages", ['title' => 'Oila'])->json('stage.id');

        $this->as(700)->patchJson("/api/teacher/stages/{$one}", [
            'title' => 'Salomlashish',
            'words' => [
                ['en' => 'hello', 'translation' => 'salom'],
                ['en' => 'goodbye', 'translation' => 'xayr'],
            ],
        ])->assertSuccessful();

        $groupId = $this->as(700)->postJson('/api/teacher/groups', [
            'title' => '5-A sinf', 'badge' => '5A', 'path_id' => $pathId,
        ])->json('group.id');

        $this->as(800, 'Dilnoza')->postJson('/api/onboarding', [
            'native_lang' => 'uz', 'study_days' => ['Du'], 'reminder_at' => '19:00',
            'cefr_level' => 'A1', 'daily_goal' => 5,
        ])->assertSuccessful();

        $code = Group::find($groupId)->code;
        $this->as(800)->postJson('/api/groups/join', ['code' => $code])->assertSuccessful();

        $student = User::where('telegram_id', 800)->first();
        $member = GroupMember::where('user_id', $student->id)->first();
        $this->as(700)->postJson("/api/teacher/members/{$member->id}/approve")->assertSuccessful();

        return [
            'path' => $pathId, 'stage' => $one, 'stage2' => $two,
            'group' => $groupId, 'student' => $student, 'code' => $code,
        ];
    }

    public function test_the_path_list_carries_the_counts_the_switcher_shows(): void
    {
        $class = $this->classroom();

        $path = $this->as(700)->getJson('/api/teacher/paths')
            ->assertSuccessful()
            ->json('paths.0');

        $this->assertSame(2, $path['stages_count']);
        $this->assertSame(2, $path['words_count']);       // only stage one is filled
        $this->assertSame(1, $path['groups_count']);
    }

    public function test_the_group_road_reports_a_class_average_per_stage(): void
    {
        $class = $this->classroom();

        $road = $this->as(700)->getJson("/api/teacher/groups/{$class['group']}/road")
            ->assertSuccessful();

        $road->assertJsonCount(2, 'stages');
        $this->assertSame('5-A sinf', $road->json('group.title'));

        // Nobody has practised, so the first stage is open at 0% and the
        // second is still locked for everyone.
        $this->assertSame('in_progress', $road->json('stages.0.status'));
        $this->assertSame('locked', $road->json('stages.1.status'));
        $this->assertSame(0, $road->json('average'));
    }

    public function test_stage_results_name_the_exercises_a_student_is_failing(): void
    {
        $class = $this->classroom();

        // Good at flashcards, hopeless at spelling.
        foreach (PathStage::find($class['stage'])->words as $word) {
            WordProgress::create([
                'user_id' => $class['student']->id,
                'word_id' => $word->id,
                'm_card' => 90, 'm_uz2en' => 80, 'm_en2uz' => 75,
                'm_spell' => 10, 'm_image' => 30, 'm_match' => 65,
                'overall' => 58,
            ]);
        }

        $results = $this->as(700)
            ->getJson("/api/teacher/groups/{$class['group']}/stages/{$class['stage']}/results")
            ->assertSuccessful();

        $results->assertJsonCount(1, 'students');
        $results->assertJsonCount(2, 'siblings');

        $weak = collect($results->json('students.0.weak'));
        $this->assertSame(['spell', 'image', 'match'], $weak->pluck('key')->all());
        $this->assertSame(10, $weak->firstWhere('key', 'spell')['score']);
        $this->assertSame('Imlo', $weak->firstWhere('key', 'spell')['label']);
    }

    public function test_a_stage_from_another_path_is_not_a_valid_result_target(): void
    {
        $class = $this->classroom();

        $otherPath = $this->as(700)->postJson('/api/teacher/paths', ['title' => 'IELTS'])->json('path.id');
        $stray = $this->as(700)->postJson("/api/teacher/paths/{$otherPath}/stages", [])->json('stage.id');

        $this->as(700)->getJson("/api/teacher/groups/{$class['group']}/stages/{$stray}/results")
            ->assertStatus(404);
    }

    public function test_a_teacher_finds_and_adds_a_student_by_id(): void
    {
        $class = $this->classroom();

        $this->as(900, 'Sardor')->getJson('/api/me')->assertSuccessful();

        $found = $this->as(700)->getJson("/api/teacher/groups/{$class['group']}/candidates?q=900")
            ->assertSuccessful()
            ->json('students');

        $this->assertCount(1, $found);
        $this->assertSame(900, (int) $found[0]['telegram_id']);
        $this->assertFalse($found[0]['already_in']);

        $this->as(700)->postJson("/api/teacher/groups/{$class['group']}/members", [
            'user_id' => $found[0]['id'],
        ])->assertSuccessful()->assertJsonPath('status', 'active');

        // Added straight away, and the path lands on their map.
        $sardor = User::where('telegram_id', 900)->first();
        $this->assertSame(2, Category::where('user_id', $sardor->id)->whereNotNull('group_id')->count());

        // A second attempt is refused rather than silently duplicated.
        $this->as(700)->postJson("/api/teacher/groups/{$class['group']}/members", [
            'user_id' => $sardor->id,
        ])->assertStatus(409);
    }

    public function test_a_stage_can_be_played_without_any_group(): void
    {
        $class = $this->classroom();

        $competition = $this->as(700)
            ->postJson("/api/teacher/stages/{$class['stage']}/competitions")
            ->assertSuccessful()
            ->json('competition');

        $this->assertTrue($competition['open']);
        $this->assertSame('Ochiq oʼyin', $competition['group']);
        $this->assertStringContainsString('comp_', $competition['invite_link']);

        // Anybody with the link may join an open game, class or not.
        $this->as(901, 'Begzod')->getJson('/api/me');
        $this->as(901)->postJson("/api/competitions/{$competition['code']}/join")
            ->assertSuccessful()
            ->assertJsonPath('competition.joined', true);
    }

    public function test_a_group_game_still_turns_outsiders_away(): void
    {
        $class = $this->classroom();

        $code = $this->as(700)->postJson("/api/teacher/groups/{$class['group']}/competitions", [
            'path_stage_id' => $class['stage'],
        ])->assertSuccessful()->json('competition.code');

        $this->as(902, 'Chetdan')->getJson('/api/me');
        $this->as(902)->postJson("/api/competitions/{$code}/join")->assertStatus(403);
    }

    public function test_a_teacher_cannot_open_a_game_on_someone_elses_stage(): void
    {
        $class = $this->classroom();

        $this->as(701, 'Boshqa')->postJson('/api/me/role', ['role' => 'teacher']);

        $this->as(701)->postJson("/api/teacher/stages/{$class['stage']}/competitions")
            ->assertStatus(403);
    }

    public function test_editing_a_stage_pushes_the_new_words_to_the_class(): void
    {
        $class = $this->classroom();

        $category = Category::where('user_id', $class['student']->id)
            ->where('path_stage_id', $class['stage'])
            ->first();

        $this->assertSame(2, $category->words_count);

        $this->as(700)->patchJson("/api/teacher/stages/{$class['stage']}", [
            'title' => 'Salomlashish',
            'words' => [
                ['en' => 'hello', 'translation' => 'salom'],
                ['en' => 'goodbye', 'translation' => 'xayr'],
                ['en' => 'welcome', 'translation' => 'xush kelibsiz'],
            ],
        ])->assertSuccessful();

        $this->assertSame(3, $category->fresh()->words_count);
    }

    public function test_deleting_a_stage_closes_the_gap_in_the_numbering(): void
    {
        $class = $this->classroom();

        $third = $this->as(700)->postJson("/api/teacher/paths/{$class['path']}/stages", ['title' => 'Maktab'])->json('stage.id');

        $this->as(700)->deleteJson("/api/teacher/stages/{$class['stage2']}")->assertSuccessful();

        $this->assertSame(1, PathStage::find($class['stage'])->position);
        $this->assertSame(2, PathStage::find($third)->position);
        $this->assertSame(2, \App\Models\Path::find($class['path'])->fresh()->stages_count);
    }

    public function test_a_path_in_use_by_a_class_cannot_be_deleted(): void
    {
        $class = $this->classroom();

        $this->as(700)->deleteJson("/api/teacher/paths/{$class['path']}")->assertStatus(409);
    }

    public function test_deleting_a_group_takes_its_stages_off_every_map(): void
    {
        $class = $this->classroom();

        $this->assertSame(2, Category::where('user_id', $class['student']->id)
            ->whereNotNull('group_id')->count());

        $this->as(700)->deleteJson("/api/teacher/groups/{$class['group']}")->assertSuccessful();

        $this->assertSame(0, Category::where('user_id', $class['student']->id)
            ->whereNotNull('group_id')->count());
    }

    public function test_the_profile_reports_the_teachers_id_and_numbers(): void
    {
        $this->classroom();

        $profile = $this->as(700)->getJson('/api/teacher/profile')->assertSuccessful();

        $this->assertMatchesRegularExpression('/^TCHR-\d{4}$/', $profile->json('teacher_ref'));
        $this->assertSame(1, $profile->json('paths'));
        $this->assertSame(1, $profile->json('groups'));
        $this->assertSame(1, $profile->json('students'));
        $this->assertSame(10, $profile->json('plan.seats'));
        $this->assertSame(1, $profile->json('plan.seats_used'));
    }

    public function test_the_teacher_id_is_minted_once_and_kept(): void
    {
        $this->as(700, 'Anvar')->postJson('/api/me/role', ['role' => 'teacher']);

        $first = $this->as(700)->getJson('/api/teacher/profile')->json('teacher_ref');
        $again = $this->as(700)->getJson('/api/teacher/profile')->json('teacher_ref');

        $this->assertSame($first, $again);
        $this->assertSame($first, $this->as(700)->getJson('/api/me')->json('user.teacher_ref'));
    }

    public function test_choosing_a_paid_plan_records_a_pending_request(): void
    {
        $this->as(700, 'Anvar')->postJson('/api/me/role', ['role' => 'teacher']);

        $plan = $this->as(700)->postJson('/api/teacher/plan', ['seats' => 30])
            ->assertSuccessful()
            ->json('plan');

        $this->assertSame('pending', $plan['status']);
        $this->assertSame(30, $plan['requested_seats']);
        // Not granted until it is paid for.
        $this->assertSame(10, $plan['seats']);

        $this->as(700)->postJson('/api/teacher/plan', ['seats' => 999])->assertStatus(422);
    }

    public function test_the_billing_mode_switches_between_the_two_tabs(): void
    {
        $this->as(700, 'Anvar')->postJson('/api/me/role', ['role' => 'teacher']);

        $this->as(700)->postJson('/api/teacher/plan/mode', ['mode' => 'student'])
            ->assertSuccessful()
            ->assertJsonPath('plan.billing_mode', 'student');

        $this->as(700)->postJson('/api/teacher/plan/mode', ['mode' => 'nonsense'])
            ->assertStatus(422);
    }

    public function test_unpaid_students_can_be_reminded(): void
    {
        $class = $this->classroom();

        $this->as(700)->postJson('/api/teacher/plan/remind')
            ->assertSuccessful()
            ->assertJsonPath('reminded', 1);

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $class['student']->id,
            'type' => 'payment',
        ]);
    }

    public function test_the_dashboard_carries_everything_the_home_screen_draws(): void
    {
        $this->classroom();

        $this->as(700)->getJson('/api/teacher/dashboard')
            ->assertSuccessful()
            ->assertJsonStructure([
                'name', 'students', 'groups', 'paths', 'stages', 'active_today',
                'week', 'week_total', 'today_index', 'pending',
                'top_groups', 'top_students', 'plan' => ['seats', 'seats_used'],
            ]);
    }

    public function test_the_leaderboard_carries_the_membership_id_for_removal(): void
    {
        $class = $this->classroom();

        $row = $this->as(700)->getJson("/api/teacher/groups/{$class['group']}")
            ->assertSuccessful()
            ->json('leaderboard.0');

        $this->assertNotNull($row['member_id']);

        $this->as(700)->deleteJson("/api/teacher/members/{$row['member_id']}")->assertSuccessful();
        $this->assertSame(0, Group::find($class['group'])->fresh()->members_count);
    }

    public function test_a_group_cannot_borrow_another_teachers_path(): void
    {
        $class = $this->classroom();

        $this->as(701, 'Boshqa')->postJson('/api/me/role', ['role' => 'teacher']);
        $mine = $this->as(701)->postJson('/api/teacher/paths', ['title' => 'Meniki'])->json('path.id');

        $this->as(700)->patchJson("/api/teacher/groups/{$class['group']}/path", ['path_id' => $mine])
            ->assertStatus(403);
    }

    public function test_the_role_question_is_asked_only_once(): void
    {
        $this->assertFalse($this->as(800, 'Dilnoza')->getJson('/api/me')->json('user.role_chosen'));

        $this->as(800)->postJson('/api/me/role', ['role' => 'student'])
            ->assertJsonPath('user.role_chosen', true);

        $this->assertTrue($this->as(800)->getJson('/api/me')->json('user.role_chosen'));
    }

    public function test_a_teacher_moving_to_the_student_side_gets_a_map(): void
    {
        $this->as(700, 'Anvar')->postJson('/api/me/role', ['role' => 'teacher']);

        $teacher = User::where('telegram_id', 700)->first();
        $this->assertSame(0, Category::where('user_id', $teacher->id)->count());

        $this->as(700)->postJson('/api/me/role', ['role' => 'student'])
            ->assertSuccessful()
            ->assertJsonPath('user.role', 'student');

        $this->assertGreaterThan(0, Category::where('user_id', $teacher->id)->count());
    }

    public function test_the_way_back_to_teaching_is_advertised_to_ex_teachers(): void
    {
        $this->classroom();

        $this->as(700)->postJson('/api/me/role', ['role' => 'student']);

        $this->as(700)->getJson('/api/me')
            ->assertJsonPath('user.role', 'student')
            ->assertJsonPath('user.has_teaching', true);

        // A student who never taught is not told they have anything to go back to.
        $this->as(800)->getJson('/api/me')->assertJsonPath('user.has_teaching', false);
    }
}
