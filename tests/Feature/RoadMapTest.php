<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Models\Word;
use App\Services\Game\RoadMapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The personal road keeps its own rhythm — three stages, then an exam —
 * however many class stages a teacher appends to the same map.
 */
class RoadMapTest extends TestCase
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

    /** A student with a personal road who then joins a class with two stages. */
    protected function studentInAClass(): User
    {
        $this->as(800, 'Dilnoza')->postJson('/api/onboarding', [
            'native_lang' => 'uz', 'study_days' => ['Du'], 'reminder_at' => '19:00',
            'cefr_level' => 'A1', 'daily_goal' => 5,
        ])->assertSuccessful();

        $this->as(700, 'Anvar')->postJson('/api/me/role', ['role' => 'teacher']);
        $pathId = $this->as(700)->postJson('/api/teacher/paths', ['title' => '5-sinf'])->json('path.id');

        foreach (['Maktab', 'Oila'] as $title) {
            $stageId = $this->as(700)->postJson("/api/teacher/paths/{$pathId}/stages", ['title' => $title])->json('stage.id');
            $this->as(700)->patchJson("/api/teacher/stages/{$stageId}", [
                'title' => $title,
                'words' => Word::orderBy('id')->take(5)->pluck('id')->all(),
            ])->assertSuccessful();
        }

        $groupId = $this->as(700)->postJson('/api/teacher/groups', [
            'title' => '5-A', 'badge' => '5A', 'path_id' => $pathId,
        ])->json('group.id');

        $this->as(800)->postJson('/api/groups/join', ['code' => Group::find($groupId)->code])->assertSuccessful();
        $member = GroupMember::where('group_id', $groupId)->first();
        $this->as(700)->postJson("/api/teacher/members/{$member->id}/approve")->assertSuccessful();

        return User::where('telegram_id', 800)->first();
    }

    public function test_the_personal_road_keeps_its_exam_rhythm_after_joining_a_class(): void
    {
        $student = $this->studentInAClass();

        $this->assertSame(2, Category::where('user_id', $student->id)->whereNotNull('group_id')->count());

        // Play through the personal road far enough for it to grow past the
        // class stages sitting at positions 9 and 10.
        $road = app(RoadMapService::class);
        foreach (Category::where('user_id', $student->id)->whereNull('group_id')->orderBy('position')->take(6)->get() as $node) {
            $road->complete($node);
        }

        $personal = collect($this->as(800)->getJson('/api/road')->json('nodes'))
            ->where('path', 'personal')
            ->sortBy('position')
            ->values();

        $this->assertSame(range(1, $personal->count()), $personal->pluck('position')->all());
        $this->assertGreaterThan(8, $personal->count());

        foreach ($personal as $node) {
            $this->assertSame($node['position'] % 4 === 0 ? 'exam' : 'normal', $node['type'],
                "node {$node['position']} has the wrong type");
        }
    }

    public function test_finishing_a_node_opens_the_next_personal_one_not_a_class_stage(): void
    {
        $student = $this->studentInAClass();
        $road = app(RoadMapService::class);

        // Personal nodes 1–8 exist, the class took 9 and 10. Completing 8
        // must open the personal node created after them.
        $personal = Category::where('user_id', $student->id)->whereNull('group_id')->orderBy('position')->get();
        foreach ($personal->take(7) as $node) {
            $road->complete($node);
        }

        $next = $road->complete($personal[7]);

        $this->assertNull($next->group_id);
        $this->assertSame('in_progress', $next->fresh()->status);
        $this->assertSame('locked', Category::where('user_id', $student->id)->whereNotNull('group_id')->orderBy('position')->skip(1)->first()->status);
    }

    public function test_repair_puts_the_rhythm_back_on_a_road_bent_by_class_positions(): void
    {
        $student = $this->studentInAClass();

        // Nodes the old code would have produced: typed by raw position.
        $position = (int) Category::where('user_id', $student->id)->max('position');
        foreach (range(1, 4) as $i) {
            Category::create([
                'user_id' => $student->id,
                'position' => ++$position,
                'type' => $position % 4 === 0 ? 'exam' : 'normal',
                'status' => 'locked',
            ]);
        }

        $this->artisan('road:repair')->assertSuccessful();

        $types = Category::where('user_id', $student->id)->whereNull('group_id')->orderBy('position')->pluck('type')->values();

        foreach ($types as $i => $type) {
            $this->assertSame(($i + 1) % 4 === 0 ? 'exam' : 'normal', $type, 'ordinal '.($i + 1));
        }
    }

    public function test_a_class_road_shows_unwritten_stages_as_grey_placeholders(): void
    {
        $student = $this->studentInAClass();

        $road = collect($this->as(800)->getJson('/api/road')->json('nodes'));
        $groupId = (string) Category::where('user_id', $student->id)->whereNotNull('group_id')->value('group_id');
        $class = $road->where('path', $groupId)->sortBy('position')->values();

        // The ten pre-drawn, still empty stages plus the two the teacher
        // wrote: the whole road, in the teacher's numbering, nothing skipped.
        $this->assertCount(12, $class);
        $this->assertSame(range(1, 12), $class->pluck('position')->all());

        $written = $class->where('placeholder', false)->values();
        $ghosts = $class->where('placeholder', true);

        $this->assertSame([11, 12], $written->pluck('position')->all());
        $this->assertCount(10, $ghosts);
        $this->assertTrue($ghosts->every(fn ($n) => $n['status'] === 'locked' && $n['lock_reason'] === 'unwritten'));

        // The first written lesson is open, the second waits its turn.
        $this->assertSame(['in_progress', 'locked'], $written->pluck('status')->all());

        // Personal nodes are untouched by any of this.
        $this->assertTrue($road->where('path', 'personal')->every(fn ($n) => ! $n['placeholder']));
    }
}
