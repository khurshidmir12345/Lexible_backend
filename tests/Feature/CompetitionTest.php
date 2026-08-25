<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\TestSession;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompetitionTest extends TestCase
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

    /** A teacher, a stage with words, a group, and two approved students. */
    protected function classroom(): array
    {
        $this->as(700, 'Anvar')->postJson('/api/me/role', ['role' => 'teacher']);

        $pathId = $this->as(700)->postJson('/api/teacher/paths', ['title' => '5-sinf'])->json('path.id');
        $stageId = $this->as(700)->postJson("/api/teacher/paths/{$pathId}/stages", ['title' => 'Maktab'])->json('stage.id');

        $this->as(700)->patchJson("/api/teacher/stages/{$stageId}", [
            'title' => 'Maktab',
            'words' => [
                ['en' => 'hello', 'translation' => 'salom'],
                ['en' => 'goodbye', 'translation' => 'xayr'],
                ['en' => 'school', 'translation' => 'maktab'],
            ],
        ])->assertSuccessful();

        $groupId = $this->as(700)->postJson('/api/teacher/groups', [
            'title' => '5-A sinf',
            'badge' => '5A',
            'path_id' => $pathId,
        ])->json('group.id');

        $code = Group::find($groupId)->code;

        foreach ([[800, 'Dilnoza'], [801, 'Sardor']] as [$id, $name]) {
            $this->as($id, $name)->postJson('/api/groups/join', ['code' => $code]);
            $member = GroupMember::where('group_id', $groupId)
                ->whereHas('user', fn ($q) => $q->where('telegram_id', $id))
                ->first();
            $this->as(700)->postJson("/api/teacher/members/{$member->id}/approve");
        }

        return ['path' => $pathId, 'stage' => $stageId, 'group' => $groupId];
    }

    protected function openLobby(array $class): string
    {
        return $this->as(700)
            ->postJson("/api/teacher/groups/{$class['group']}/competitions", ['path_stage_id' => $class['stage']])
            ->assertSuccessful()
            ->json('competition.code');
    }

    public function test_a_teacher_opens_a_lobby_that_lists_the_whole_class(): void
    {
        $class = $this->classroom();

        $response = $this->as(700)
            ->postJson("/api/teacher/groups/{$class['group']}/competitions", ['path_stage_id' => $class['stage']])
            ->assertSuccessful();

        $response->assertJsonPath('competition.status', 'lobby')
            ->assertJsonPath('competition.joined_count', 0)
            ->assertJsonCount(2, 'competition.students');

        $this->assertStringContainsString('startapp=comp_', $response->json('competition.invite_link'));
        $this->assertSame(['absent', 'absent'], collect($response->json('competition.students'))->pluck('status')->all());
    }

    public function test_a_student_who_is_not_in_the_group_cannot_join(): void
    {
        $class = $this->classroom();
        $code = $this->openLobby($class);

        $this->as(900, 'Begona')->postJson('/api/me/role', ['role' => 'student']);
        $this->as(900)->postJson("/api/competitions/{$code}/join")->assertStatus(403);
    }

    public function test_joining_marks_the_student_ready_in_the_lobby(): void
    {
        $class = $this->classroom();
        $code = $this->openLobby($class);

        $this->as(800)->postJson("/api/competitions/{$code}/join")
            ->assertSuccessful()
            ->assertJsonPath('competition.joined', true);

        $competition = Competition::where('code', $code)->first();

        $lobby = $this->as(700)->getJson("/api/teacher/competitions/{$competition->id}")
            ->assertSuccessful()
            ->json('competition');

        $this->assertSame(1, $lobby['joined_count']);
        $this->assertSame(
            ['Dilnoza' => 'ready', 'Sardor' => 'absent'],
            collect($lobby['students'])->pluck('status', 'name')->all(),
        );
    }

    public function test_questions_are_withheld_until_the_teacher_starts(): void
    {
        $class = $this->classroom();
        $code = $this->openLobby($class);

        $this->as(800)->postJson("/api/competitions/{$code}/join");
        $this->as(800)->postJson("/api/competitions/{$code}/session")->assertStatus(409);
    }

    public function test_an_empty_lobby_cannot_be_started(): void
    {
        $class = $this->classroom();
        $code = $this->openLobby($class);
        $competition = Competition::where('code', $code)->first();

        $this->as(700)->postJson("/api/teacher/competitions/{$competition->id}/start")->assertStatus(422);
    }

    public function test_every_participant_answers_the_same_frozen_paper(): void
    {
        $class = $this->classroom();
        $code = $this->openLobby($class);
        $competition = Competition::where('code', $code)->first();

        $this->as(800)->postJson("/api/competitions/{$code}/join");
        $this->as(801)->postJson("/api/competitions/{$code}/join");
        $this->as(700)->postJson("/api/teacher/competitions/{$competition->id}/start")->assertSuccessful();

        $first = $this->as(800)->postJson("/api/competitions/{$code}/session")->assertSuccessful();
        $second = $this->as(801)->postJson("/api/competitions/{$code}/session")->assertSuccessful();

        $this->assertSame(
            collect($first->json('questions'))->pluck('word_id')->sort()->values()->all(),
            collect($second->json('questions'))->pluck('word_id')->sort()->values()->all(),
        );

        // Asking twice must not hand out a second paper.
        $again = $this->as(800)->postJson("/api/competitions/{$code}/session")->json('session_id');
        $this->assertSame($first->json('session_id'), $again);
    }

    public function test_the_board_ranks_by_score_then_by_time(): void
    {
        $class = $this->classroom();
        $code = $this->openLobby($class);
        $competition = Competition::where('code', $code)->first();

        $this->as(800)->postJson("/api/competitions/{$code}/join");
        $this->as(801)->postJson("/api/competitions/{$code}/join");
        $this->as(700)->postJson("/api/teacher/competitions/{$competition->id}/start");

        $this->as(800)->postJson("/api/competitions/{$code}/session");
        $this->as(801)->postJson("/api/competitions/{$code}/session");

        // Sardor scores the same but is slower, so Dilnoza takes first place.
        $this->as(800)->postJson("/api/competitions/{$code}/finish",
            ['score' => 5, 'total' => 6, 'duration_ms' => 60000])->assertSuccessful();
        $this->as(801)->postJson("/api/competitions/{$code}/finish",
            ['score' => 5, 'total' => 6, 'duration_ms' => 91000])->assertSuccessful();

        $board = $this->as(700)->getJson("/api/teacher/competitions/{$competition->id}/results")
            ->assertSuccessful()
            ->json('competition');

        $this->assertSame('finished', $board['status']);
        $this->assertSame(2, $board['participants']);
        $this->assertSame(['Dilnoza', 'Sardor'], collect($board['standings'])->pluck('name')->all());
        $this->assertSame([1, 2], collect($board['standings'])->pluck('rank')->all());
        $this->assertSame('1:00', $board['standings'][0]['duration']);
        $this->assertSame(83, $board['standings'][0]['accuracy']);
    }

    public function test_the_round_closes_on_its_own_once_everyone_has_finished(): void
    {
        $class = $this->classroom();
        $code = $this->openLobby($class);
        $competition = Competition::where('code', $code)->first();

        $this->as(800)->postJson("/api/competitions/{$code}/join");
        $this->as(700)->postJson("/api/teacher/competitions/{$competition->id}/start");
        $this->as(800)->postJson("/api/competitions/{$code}/session");

        $this->as(800)->postJson("/api/competitions/{$code}/finish",
            ['score' => 6, 'total' => 6, 'duration_ms' => 42000]);

        $this->assertSame('finished', $competition->fresh()->status);
    }

    public function test_a_teacher_can_end_a_round_that_is_still_running(): void
    {
        $class = $this->classroom();
        $code = $this->openLobby($class);
        $competition = Competition::where('code', $code)->first();

        $this->as(800)->postJson("/api/competitions/{$code}/join");
        $this->as(801)->postJson("/api/competitions/{$code}/join");
        $this->as(700)->postJson("/api/teacher/competitions/{$competition->id}/start");

        $this->as(800)->postJson("/api/competitions/{$code}/session");
        $this->as(800)->postJson("/api/competitions/{$code}/finish",
            ['score' => 4, 'total' => 6, 'duration_ms' => 50000]);

        // Sardor never finished; the board still has to close.
        $board = $this->as(700)->postJson("/api/teacher/competitions/{$competition->id}/close")
            ->assertSuccessful()
            ->json('competition');

        $this->assertSame('finished', $board['status']);
        $this->assertSame(1, $board['standings'][0]['rank']);
        $this->assertFalse($board['standings'][1]['finished']);
    }

    public function test_a_rival_teacher_cannot_read_the_lobby(): void
    {
        $class = $this->classroom();
        $code = $this->openLobby($class);
        $competition = Competition::where('code', $code)->first();

        $this->as(701, 'Nodira')->postJson('/api/me/role', ['role' => 'teacher']);
        $this->as(701)->getJson("/api/teacher/competitions/{$competition->id}")->assertStatus(403);
    }

    public function test_a_competition_scores_into_the_students_own_copy_of_the_stage(): void
    {
        $class = $this->classroom();

        // Opening the group stage gives the student their own category for it.
        $this->as(800)->getJson('/api/road');
        $code = $this->openLobby($class);
        $competition = Competition::where('code', $code)->first();

        $this->as(800)->postJson("/api/competitions/{$code}/join");
        $this->as(700)->postJson("/api/teacher/competitions/{$competition->id}/start");

        $sessionId = $this->as(800)->postJson("/api/competitions/{$code}/session")->json('session_id');
        $session = TestSession::find($sessionId);

        $this->assertSame($competition->id, $session->competition_id);
        $this->assertNotNull($session->category_id);
    }
}
