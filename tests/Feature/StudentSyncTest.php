<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Path;
use App\Models\PathStage;
use App\Models\User;
use App\Models\Word;
use App\Services\Game\TestBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where the two apps meet: how a student reaches a class, and what a teacher's
 * stage is allowed to become once it is on the student's map.
 */
class StudentSyncTest extends TestCase
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

        // A pictured dictionary, so picture questions have somewhere to draw
        // their decoys from.
        foreach (range(1, 14) as $i) {
            Word::create([
                'word' => "word{$i}",
                'part_of_speech' => 'noun',
                'translations' => ['uz' => ["soz{$i}"]],
                'emoji' => '📘',
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

    /** A teacher with one filled stage and one class. */
    protected function teacher(int $id = 700, string $name = 'Anvar'): array
    {
        $this->as($id, $name)->postJson('/api/me/role', ['role' => 'teacher'])->assertSuccessful();

        $pathId = $this->as($id)->postJson('/api/teacher/paths', ['title' => '5-sinf'])->json('path.id');
        $stageId = $this->as($id)->postJson("/api/teacher/paths/{$pathId}/stages", ['title' => 'Salomlashish'])->json('stage.id');

        $this->as($id)->patchJson("/api/teacher/stages/{$stageId}", [
            'title' => 'Salomlashish',
            'words' => [
                ['en' => 'hello', 'translation' => 'salom'],
                ['en' => 'goodbye', 'translation' => 'xayr'],
                ['en' => 'please', 'translation' => 'iltimos'],
            ],
        ])->assertSuccessful();

        $groupId = $this->as($id)->postJson('/api/teacher/groups', [
            'title' => '5-A sinf', 'badge' => '5A', 'path_id' => $pathId,
        ])->json('group.id');

        return [
            'user' => User::where('telegram_id', $id)->first(),
            'path' => $pathId,
            'stage' => $stageId,
            'group' => $groupId,
            'code' => Group::find($groupId)->code,
        ];
    }

    protected function student(int $id = 800, string $name = 'Dilnoza', ?string $teacherCode = null): User
    {
        $this->as($id, $name)->postJson('/api/onboarding', [
            'native_lang' => 'uz', 'study_days' => ['Du'], 'reminder_at' => '19:00',
            'cefr_level' => 'A1', 'daily_goal' => 5,
            'teacher_code' => $teacherCode,
        ])->assertSuccessful();

        return User::where('telegram_id', $id)->first();
    }

    protected function approveLatest(int $teacherId = 700): void
    {
        $member = GroupMember::where('status', 'pending')->latest()->first();
        $this->as($teacherId)->postJson("/api/teacher/members/{$member->id}/approve")->assertSuccessful();
    }

    // ------------------------------------------------------------ reaching a class

    public function test_a_student_joins_with_the_teachers_own_id(): void
    {
        $class = $this->teacher();
        $ref = $class['user']->fresh()->teacher_ref;

        $this->assertMatchesRegularExpression('/^TCHR-\d{4}$/', $ref);

        $this->student();

        $this->as(800)->postJson('/api/groups/join', ['code' => $ref])
            ->assertSuccessful()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('group.title', '5-A sinf');
    }

    public function test_the_id_is_read_the_way_it_is_spoken(): void
    {
        $class = $this->teacher();
        $ref = $class['user']->fresh()->teacher_ref;

        $this->student();

        // Lower case, and a space where the dash belongs.
        $spoken = strtolower(str_replace('-', ' ', $ref));

        $this->as(800)->postJson('/api/groups/join', ['code' => $spoken])
            ->assertSuccessful()
            ->assertJsonPath('status', 'pending');
    }

    public function test_one_id_covering_several_classes_asks_which(): void
    {
        $class = $this->teacher();
        $this->as(700)->postJson('/api/teacher/groups', ['title' => '6-B sinf', 'badge' => '6B']);

        $ref = $class['user']->fresh()->teacher_ref;
        $this->student();

        $answer = $this->as(800)->postJson('/api/groups/join', ['code' => $ref])
            ->assertSuccessful()
            ->assertJsonPath('status', 'choose');

        $groups = $answer->json('groups');
        $this->assertCount(2, $groups);
        $this->assertSame(0, GroupMember::count(), 'nothing is joined until the student picks');

        $this->as(800)->postJson('/api/groups/join', ['code' => $ref, 'group_id' => $groups[1]['id']])
            ->assertSuccessful()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('group.title', '6-B sinf');
    }

    public function test_a_teacher_with_no_class_yet_says_so(): void
    {
        $this->as(700, 'Anvar')->postJson('/api/me/role', ['role' => 'teacher']);
        $ref = User::where('telegram_id', 700)->first()->teacher_ref;

        $this->student();

        $this->as(800)->postJson('/api/groups/join', ['code' => $ref])
            ->assertStatus(404)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'guruh yaratmagan'));
    }

    public function test_an_unknown_code_names_both_formats(): void
    {
        $this->student();

        $this->as(800)->postJson('/api/groups/join', ['code' => 'YOQ-KOD'])
            ->assertStatus(404)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'TCHR'));
    }

    public function test_the_onboarding_teacher_field_actually_sends_the_request(): void
    {
        $class = $this->teacher();

        // This used to be stored as a string and forgotten.
        $this->student(800, 'Dilnoza', $class['code'])
            ->refresh();

        $this->assertSame(1, GroupMember::where('status', 'pending')->count());

        $this->as(800)->getJson('/api/groups/mine')
            ->assertJsonPath('groups.0.title', '5-A sinf')
            ->assertJsonPath('groups.0.status', 'pending');
    }

    public function test_a_bad_code_in_onboarding_is_reported_not_swallowed(): void
    {
        $response = $this->as(800, 'Dilnoza')->postJson('/api/onboarding', [
            'native_lang' => 'uz', 'study_days' => ['Du'], 'reminder_at' => '19:00',
            'cefr_level' => 'A1', 'daily_goal' => 5, 'teacher_code' => 'YOQ-KOD',
        ])->assertSuccessful();

        // Onboarding still completes — the map is built either way.
        $this->assertTrue($response->json('user.onboarded'));
        $this->assertNotNull($response->json('teacher_problem'));
        $this->assertNull($response->json('teacher_request'));
    }

    public function test_a_student_can_withdraw_a_request(): void
    {
        $class = $this->teacher();
        $this->student();

        $this->as(800)->postJson('/api/groups/join', ['code' => $class['code']]);
        $this->assertSame(1, GroupMember::where('status', 'pending')->count());

        $this->as(800)->deleteJson("/api/groups/{$class['group']}/leave")->assertSuccessful();
        $this->assertSame(0, GroupMember::where('status', 'pending')->count());
    }

    // ------------------------------------------------- the teacher keeps control

    public function test_a_teachers_stage_is_read_only_for_the_student(): void
    {
        $class = $this->teacher();
        $student = $this->student();

        $this->as(800)->postJson('/api/groups/join', ['code' => $class['code']]);
        $this->approveLatest();

        $category = Category::where('user_id', $student->id)->whereNotNull('group_id')->first();
        $wordId = $category->words()->first()->id;

        $this->as(800)->patchJson("/api/categories/{$category->id}", ['title' => 'Meniki'])
            ->assertStatus(403);

        $this->as(800)->postJson("/api/categories/{$category->id}/words", ['word_id' => Word::first()->id])
            ->assertStatus(403);

        $this->as(800)->deleteJson("/api/categories/{$category->id}/words/{$wordId}")
            ->assertStatus(403);

        // The lesson is intact, which is what the teacher's results screen counts.
        $this->assertSame(3, $category->fresh()->words()->count());
        $this->assertSame('Salomlashish', $category->fresh()->title);
    }

    public function test_the_stage_tells_the_app_whose_it_is(): void
    {
        $class = $this->teacher();
        $student = $this->student();

        $this->as(800)->postJson('/api/groups/join', ['code' => $class['code']]);
        $this->approveLatest();

        $category = Category::where('user_id', $student->id)->whereNotNull('group_id')->first();

        $this->as(800)->getJson("/api/categories/{$category->id}")
            ->assertSuccessful()
            ->assertJsonPath('category.from_group', true)
            ->assertJsonPath('category.editable', false)
            ->assertJsonPath('category.group.title', '5-A sinf')
            ->assertJsonPath('category.group.teacher', 'Anvar')
            // The class numbering, not the student's own map position.
            ->assertJsonPath('category.position', 1);
    }

    public function test_an_empty_teacher_stage_is_never_auto_filled(): void
    {
        $class = $this->teacher();

        // A second stage the teacher has created but not written yet.
        $empty = $this->as(700)->postJson("/api/teacher/paths/{$class['path']}/stages", ['title' => 'Oila'])->json('stage.id');

        $student = $this->student();
        $this->as(800)->postJson('/api/groups/join', ['code' => $class['code']]);
        $this->approveLatest();

        $category = Category::where('user_id', $student->id)
            ->where('path_stage_id', $empty)
            ->first();

        $this->as(800)->getJson("/api/categories/{$category->id}")
            ->assertSuccessful()
            ->assertJsonPath('auto_filled', 0)
            ->assertJsonCount(0, 'words');

        // Random dictionary words in a lesson the teacher has not written yet
        // would be overwritten the moment they do write it.
        $this->assertSame(0, $category->fresh()->words()->count());
    }

    public function test_the_players_own_stage_is_still_filled_and_editable(): void
    {
        $student = $this->student();
        $own = Category::where('user_id', $student->id)->whereNull('group_id')->first();

        $this->as(800)->getJson("/api/categories/{$own->id}")
            ->assertSuccessful()
            ->assertJsonPath('category.editable', true)
            ->assertJsonPath('category.from_group', false);

        $this->assertGreaterThan(0, $own->fresh()->words()->count());
    }

    // ------------------------------------------------------------ the exercises

    public function test_hand_typed_words_are_given_a_picture(): void
    {
        $this->as(700, 'Anvar')->postJson('/api/me/role', ['role' => 'teacher']);

        $pathId = $this->as(700)->postJson('/api/teacher/paths', ['title' => '5-sinf'])->json('path.id');
        $stageId = $this->as(700)->postJson("/api/teacher/paths/{$pathId}/stages", [])->json('stage.id');

        $this->as(700)->patchJson("/api/teacher/stages/{$stageId}", [
            'title' => 'Maktab',
            'words' => [
                ['en' => 'book', 'translation' => 'kitob'],
                ['en' => 'apple', 'translation' => 'olma'],
                // Nothing illustrates a greeting; it must not break the save.
                ['en' => 'hello', 'translation' => 'salom'],
            ],
        ])->assertSuccessful();

        // A word created from what the teacher typed still has to be askable
        // as a picture question, or a hand-written lesson loses an exercise.
        $this->assertSame('📚', Word::where('normalized', 'book')->first()->emoji);
        $this->assertNotNull(Word::where('normalized', 'apple')->first()->emoji);

        // And one that cannot be illustrated is simply left without a picture.
        $this->assertNull(Word::where('normalized', 'hello')->first()->emoji);
    }

    public function test_a_picture_question_is_skipped_rather_than_shown_blank(): void
    {
        $student = $this->student();
        $category = Category::where('user_id', $student->id)->first();

        // A word nothing can illustrate, and a dictionary with no pictures at
        // all to borrow decoys from.
        Word::query()->update(['emoji' => null, 'icon_path' => null]);

        $blind = Word::create([
            'word' => 'abstraction',
            'part_of_speech' => 'noun',
            'translations' => ['uz' => ['mavhumlik']],
        ]);

        $questions = app(TestBuilder::class)->build($category, ['image'], collect([$blind]), 'uz');

        $this->assertSame([], $questions);
    }

    public function test_a_picture_question_always_fills_the_grid(): void
    {
        $student = $this->student();
        $category = Category::where('user_id', $student->id)->first();

        // One pictured word on its own: decoys must come from the dictionary.
        $word = Word::where('normalized', 'word1')->first();

        $questions = app(TestBuilder::class)->build($category, ['image'], collect([$word]), 'uz');

        $this->assertCount(1, $questions);
        $this->assertCount(
            (int) config('game.session.choice_options'),
            $questions[0]['options'],
            'a half-empty picture grid cannot be answered',
        );
        $this->assertTrue(
            collect($questions[0]['options'])->every(fn ($o) => filled($o['emoji']) || filled($o['icon'])),
            'every tile needs something to show',
        );
    }

    // ------------------------------------------------------- teacher can track

    public function test_the_teacher_sees_the_student_move(): void
    {
        $class = $this->teacher();
        $student = $this->student();

        $this->as(800)->postJson('/api/groups/join', ['code' => $class['code']]);
        $this->approveLatest();

        $board = $this->as(700)->getJson("/api/teacher/groups/{$class['group']}")
            ->assertSuccessful()
            ->json('leaderboard');

        $this->assertCount(1, $board);
        $this->assertSame($student->id, $board[0]['id']);
        $this->assertNotNull($board[0]['member_id']);

        // And the same student shows up on the stage report.
        $this->as(700)
            ->getJson("/api/teacher/groups/{$class['group']}/stages/{$class['stage']}/results")
            ->assertSuccessful()
            ->assertJsonCount(1, 'students')
            ->assertJsonPath('students.0.id', $student->id);
    }

    public function test_words_the_teacher_adds_later_reach_the_class(): void
    {
        $class = $this->teacher();
        $student = $this->student();

        $this->as(800)->postJson('/api/groups/join', ['code' => $class['code']]);
        $this->approveLatest();

        $category = Category::where('user_id', $student->id)->whereNotNull('group_id')->first();
        $this->assertSame(3, $category->words()->count());

        $this->as(700)->patchJson("/api/teacher/stages/{$class['stage']}", [
            'title' => 'Salomlashish',
            'words' => [
                ['en' => 'hello', 'translation' => 'salom'],
                ['en' => 'goodbye', 'translation' => 'xayr'],
                ['en' => 'please', 'translation' => 'iltimos'],
                ['en' => 'welcome', 'translation' => 'xush kelibsiz'],
            ],
        ])->assertSuccessful();

        $this->assertSame(4, $category->fresh()->words()->count());
    }
}
