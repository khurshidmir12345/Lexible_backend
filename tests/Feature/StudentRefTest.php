<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The student's own ID, and joining by a teacher's ID whatever side the teacher is on today. */
class StudentRefTest extends TestCase
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

    public function test_every_player_has_a_stable_student_id(): void
    {
        $first = $this->as(800, 'Dilnoza')->getJson('/api/me')->assertSuccessful()->json('user.student_ref');
        $again = $this->as(800)->getJson('/api/me')->json('user.student_ref');

        $this->assertMatchesRegularExpression('/^ST-\d{6}$/', $first);
        $this->assertSame($first, $again);
    }

    public function test_a_teacher_finds_a_student_by_that_id_however_it_is_typed(): void
    {
        $ref = $this->as(800, 'Dilnoza')->getJson('/api/me')->json('user.student_ref');

        $this->as(700, 'Anvar')->postJson('/api/me/role', ['role' => 'teacher']);
        $groupId = $this->as(700)->postJson('/api/teacher/groups', ['title' => '5-A', 'badge' => '5A'])->json('group.id');

        foreach ([$ref, strtolower($ref), str_replace('-', '', $ref), str_replace('-', ' ', $ref)] as $typed) {
            $found = $this->as(700)->getJson("/api/teacher/groups/{$groupId}/candidates?q=".urlencode($typed))
                ->assertSuccessful()
                ->json('students');

            $this->assertSame('Dilnoza', $found[0]['name'] ?? null, "typed as {$typed}");
            $this->assertSame($ref, $found[0]['ref']);
        }
    }

    public function test_a_teachers_id_still_admits_students_while_the_teacher_is_on_the_student_side(): void
    {
        $this->as(700, 'Anvar')->postJson('/api/me/role', ['role' => 'teacher']);
        $this->as(700)->postJson('/api/teacher/groups', ['title' => '5-A', 'badge' => '5A'])->assertSuccessful();
        $ref = $this->as(700)->getJson('/api/teacher/profile')->json('teacher.teacher_ref')
            ?? User::where('telegram_id', 700)->first()->teacherRef();

        // The teacher goes to study for a while.
        $this->as(700)->postJson('/api/me/role', ['role' => 'student'])->assertSuccessful();

        $this->as(800, 'Dilnoza')->getJson('/api/me');
        $this->as(800)->postJson('/api/groups/join', ['code' => $ref])->assertSuccessful();
        $this->as(801, 'Sardor')->getJson('/api/me');
        $this->as(801)->postJson('/api/groups/join', ['code' => str_replace('-', '', strtolower($ref))])->assertSuccessful();

        $this->assertSame(2, Group::first()->memberships()->count());
    }

    public function test_the_pulse_changes_when_a_teacher_adds_the_student_to_a_class(): void
    {
        $before = $this->as(800, 'Dilnoza')->getJson('/api/pulse')->assertSuccessful()->json();
        $this->assertSame($before, $this->as(800)->getJson('/api/pulse')->json(), 'nothing happened, nothing changes');

        $this->as(700, 'Anvar')->postJson('/api/me/role', ['role' => 'teacher']);
        $groupId = $this->as(700)->postJson('/api/teacher/groups', ['title' => '5-A', 'badge' => '5A'])->json('group.id');
        $studentId = User::where('telegram_id', 800)->value('id');
        $this->as(700)->postJson("/api/teacher/groups/{$groupId}/members", ['user_id' => $studentId])->assertSuccessful();

        $after = $this->as(800)->getJson('/api/pulse')->json();

        $this->assertNotSame($before['road'], $after['road']);
        $this->assertGreaterThan($before['unread'], $after['unread']);
    }
}
