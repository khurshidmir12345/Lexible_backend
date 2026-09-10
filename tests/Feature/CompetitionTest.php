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

        // Clocks are the server's now, so the tests move time by hand.
        $this->freezeSecond();

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
            'words' => Word::orderBy('id')->take(5)->pluck('id')->all(),
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
        $this->assertMatchesRegularExpression('#^https://t\.me/[A-Za-z0-9_]+\?startapp=comp_#', $response->json('competition.invite_link'));
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

        // Both answer the same five questions right; the score and the clock
        // are the server's — what the client claims is ignored.
        foreach ([800, 801] as $id) {
            $this->answerAll($id, $code, 99);
        }

        // Sardor scores the same but is slower, so Dilnoza takes first place.
        $this->travel(60)->seconds();
        $this->as(800)->postJson("/api/competitions/{$code}/finish",
            ['score' => 99, 'total' => 99, 'duration_ms' => 1])->assertSuccessful();
        $this->travel(31)->seconds();
        $this->as(801)->postJson("/api/competitions/{$code}/finish",
            ['score' => 99, 'total' => 99, 'duration_ms' => 1])->assertSuccessful();

        $board = $this->as(700)->getJson("/api/teacher/competitions/{$competition->id}/results")
            ->assertSuccessful()
            ->json('competition');

        $this->assertSame('finished', $board['status']);
        $this->assertSame(2, $board['participants']);
        $this->assertSame(['Dilnoza', 'Sardor'], collect($board['standings'])->pluck('name')->all());
        $this->assertSame([1, 2], collect($board['standings'])->pluck('rank')->all());
        $this->assertSame('1:00', $board['standings'][0]['duration']);
        $this->assertSame('1:31', $board['standings'][1]['duration']);
        $this->assertSame(10, $board['standings'][0]['score']);
        $this->assertSame(100, $board['standings'][0]['accuracy']);
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

    public function test_an_outsider_in_an_open_game_gets_a_standalone_paper(): void
    {
        $class = $this->classroom();

        $code = $this->as(700)
            ->postJson("/api/teacher/stages/{$class['stage']}/competitions")
            ->assertSuccessful()
            ->json('competition.code');

        // Begzod is in no class, so he has no copy of the stage on his road.
        $this->as(903, 'Begzod')->getJson('/api/me');
        $this->as(903)->postJson("/api/competitions/{$code}/join")->assertSuccessful();

        $id = Competition::where('code', $code)->value('id');
        $this->as(700)->postJson("/api/teacher/competitions/{$id}/start")->assertSuccessful();

        $session = $this->as(903)->postJson("/api/competitions/{$code}/session")
            ->assertSuccessful()
            ->json();

        $this->assertNotEmpty($session['questions']);
        $this->assertNull(TestSession::find($session['session_id'])->category_id);
    }

    public function test_the_board_can_be_drawn_as_a_picture_for_the_class_group(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        // The bot API is faked: initData is still signed with the test token.
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['ok' => false], 200)]);

        $class = $this->classroom();
        $code = $this->openLobby($class);

        $this->as(800)->postJson("/api/competitions/{$code}/join");
        $this->as(801)->postJson("/api/competitions/{$code}/join");
        $id = Competition::where('code', $code)->value('id');
        $this->as(700)->postJson("/api/teacher/competitions/{$id}/start");
        $this->as(800)->postJson("/api/competitions/{$code}/finish", ['score' => 4, 'total' => 5, 'duration_ms' => 40000]);
        $this->as(801)->postJson("/api/competitions/{$code}/finish", ['score' => 2, 'total' => 5, 'duration_ms' => 50000]);

        $response = $this->as(700)->postJson("/api/competitions/{$code}/share");
        $this->assertTrue($response->isSuccessful(), $response->getContent());
        $reply = $response->json();

        $this->assertStringContainsString('/storage/share/comp-'.strtolower($code), $reply['image_url']);

        $file = \Illuminate\Support\Facades\Storage::disk('public')->allFiles('share')[0] ?? null;
        $this->assertNotNull($file);
        $info = getimagesizefromstring(\Illuminate\Support\Facades\Storage::disk('public')->get($file));
        $this->assertSame([1080, 1350], [$info[0], $info[1]]);

        // A classmate may share too; a stranger may not.
        $mate = $this->as(800)->postJson("/api/competitions/{$code}/share");
        $this->assertTrue($mate->isSuccessful(), $mate->getContent());
        $this->as(904, 'Chetdan')->getJson('/api/me');
        $this->as(904)->postJson("/api/competitions/{$code}/share")->assertStatus(403);
    }

    /** Plays the paper: the first `$right` questions correctly, the rest wrong. */
    protected function answerAll(int $telegramId, string $code, int $right): void
    {
        $data = $this->as($telegramId)->postJson("/api/competitions/{$code}/session")->assertSuccessful()->json();
        $session = TestSession::find($data['session_id']);

        foreach (collect($session->payload)->take(count($data['questions'])) as $i => $question) {
            $answer = $i < $right ? $question['answer'] : 'zzz';
            $this->as($telegramId)->postJson("/api/tests/{$session->id}/answer", [
                'question_id' => $question['id'], 'answer' => $answer,
            ])->assertSuccessful();
        }
    }

    public function test_the_teacher_picks_the_exercises_and_a_clock(): void
    {
        $class = $this->classroom();

        $lobby = $this->as(700)
            ->postJson("/api/teacher/groups/{$class['group']}/competitions", [
                'path_stage_id' => $class['stage'],
                'types' => ['spell', 'card', 'uz2en'],
                'duration_minutes' => 3,
            ])
            ->assertSuccessful()
            ->json('competition');

        // The flashcard cannot be raced on, so it is dropped from the pick.
        $this->assertSame(['uz2en', 'spell'], $lobby['types']);
        $this->assertSame(3, $lobby['duration_minutes']);
        $this->assertNull($lobby['deadline_at']);
        $this->assertNotNull($lobby['notified_at']);

        $this->as(800)->postJson("/api/competitions/{$lobby['code']}/join");
        $started = $this->as(700)->postJson("/api/teacher/competitions/{$lobby['id']}/start")->json('competition');

        $this->assertNotNull($started['deadline_at']);
        $this->assertEqualsWithDelta(180, $started['remaining_seconds'], 2);

        $mine = $this->as(800)->getJson("/api/competitions/{$lobby['code']}")->json('competition');
        $this->assertSame(3, $mine['duration_minutes']);
        $this->assertNotNull($mine['deadline_at']);
    }

    public function test_the_clock_finishes_whoever_is_still_answering(): void
    {
        $class = $this->classroom();
        $code = $this->as(700)
            ->postJson("/api/teacher/groups/{$class['group']}/competitions", [
                'path_stage_id' => $class['stage'], 'duration_minutes' => 2,
            ])->json('competition.code');
        $competition = Competition::where('code', $code)->first();

        $this->as(800)->postJson("/api/competitions/{$code}/join");
        $this->as(801)->postJson("/api/competitions/{$code}/join");
        $this->as(700)->postJson("/api/teacher/competitions/{$competition->id}/start");

        // Dilnoza gets two right and stalls; Sardor never opens the paper.
        $this->answerAll(800, $code, 2);
        $session = TestSession::where('competition_id', $competition->id)->first();
        $this->assertGreaterThan(2, $session->questions_count);

        // Not over yet: the live board shows her running score.
        $live = $this->as(700)->getJson("/api/teacher/competitions/{$competition->id}")->json('competition');
        $this->assertSame('playing', $live['status']);
        $this->assertSame(2, collect($live['students'])->firstWhere('name', 'Dilnoza')['score']);

        $this->travel(2)->minutes();
        $this->travel(5)->seconds();

        // The next answer is refused, and the board is final.
        $question = collect($session->fresh()->payload)->last();
        $this->as(800)->postJson("/api/tests/{$session->id}/answer", [
            'question_id' => $question['id'], 'answer' => $question['answer'],
        ])->assertStatus(409);

        $board = $this->as(700)->getJson("/api/teacher/competitions/{$competition->id}/results")->json('competition');
        $this->assertSame('finished', $board['status']);

        $dilnoza = collect($board['standings'])->firstWhere('name', 'Dilnoza');
        $this->assertSame(2, $dilnoza['score']);
        $this->assertSame($session->questions_count, $dilnoza['total']);
        $this->assertTrue($dilnoza['timed_out']);
        $this->assertTrue($dilnoza['finished']);
        $this->assertSame('2:00', $dilnoza['duration']);
        $this->assertSame(1, $dilnoza['rank']);

        $sardor = collect($board['standings'])->firstWhere('name', 'Sardor');
        $this->assertFalse($sardor['played']);
        $this->assertSame(2, $sardor['rank']);
        $this->assertSame('finished', TestSession::find($session->id)->status);
    }

    public function test_the_picture_waits_for_the_final_board(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['ok' => false], 200)]);

        $class = $this->classroom();
        $code = $this->openLobby($class);
        $id = Competition::where('code', $code)->value('id');

        $this->as(800)->postJson("/api/competitions/{$code}/join");
        $this->as(801)->postJson("/api/competitions/{$code}/join");
        $this->as(700)->postJson("/api/teacher/competitions/{$id}/start");
        $this->as(800)->postJson("/api/competitions/{$code}/session");
        $this->as(800)->postJson("/api/competitions/{$code}/finish", ['score' => 4, 'total' => 5, 'duration_ms' => 40000]);

        $this->as(700)->postJson("/api/competitions/{$code}/share")->assertStatus(409);

        $this->as(700)->postJson("/api/teacher/competitions/{$id}/close");
        $this->as(700)->postJson("/api/competitions/{$code}/share")->assertSuccessful();
    }

    public function test_a_name_made_of_emoji_is_drawn_as_a_label(): void
    {
        $card = new \App\Services\Game\ResultCard;

        $this->assertSame('Mirsaid', $card::plainName('Mirsaid 🐺🔥'));
        $this->assertSame('Oʼquvchi', $card::plainName('🥇🎮'));
        $this->assertSame('Ali-Vali Oʼrinov', $card::plainName('Ali-Vali Oʼrinov ✨'));
        $this->assertSame('Диёра', $card::plainName('Диёра 💫'));
    }

    public function test_the_class_can_be_called_in_again(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $class = $this->classroom();
        $code = $this->openLobby($class);
        $id = Competition::where('code', $code)->value('id');

        $this->as(800)->postJson("/api/competitions/{$code}/join");

        // Only Sardor, who has not joined, is messaged the second time.
        $reply = $this->as(700)->postJson("/api/teacher/competitions/{$id}/notify")->assertSuccessful()->json();
        $this->assertSame(1, $reply['sent']);

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SendTelegramMessage::class,
            fn ($job) => $job->replyMarkup['inline_keyboard'][0][0]['style'] === 'primary');
    }

    public function test_the_teacher_finds_a_round_they_walked_away_from(): void
    {
        $class = $this->classroom();
        $code = $this->openLobby($class);
        $this->as(800)->postJson("/api/competitions/{$code}/join");

        $rows = $this->as(700)->getJson('/api/teacher/competitions')->assertSuccessful()->json('competitions');
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['live']);
        $this->assertSame('lobby', $rows[0]['status']);
        $this->assertSame(1, $rows[0]['participants']);

        $group = $this->as(700)->getJson("/api/teacher/groups/{$class['group']}/competitions")->json('competitions');
        $this->assertSame($rows[0]['id'], $group[0]['id']);
    }

    public function test_every_paper_stays_in_the_students_record(): void
    {
        $class = $this->classroom();
        $code = $this->openLobby($class);
        $id = Competition::where('code', $code)->value('id');

        $this->as(800)->postJson("/api/competitions/{$code}/join");
        $this->as(700)->postJson("/api/teacher/competitions/{$id}/start");
        $this->answerAll(800, $code, 3);
        $this->as(800)->postJson("/api/competitions/{$code}/finish", ['score' => 0, 'total' => 0, 'duration_ms' => 0]);

        $student = \App\Models\User::where('telegram_id', 800)->first();

        $history = $this->as(700)
            ->getJson("/api/teacher/groups/{$class['group']}/students/{$student->id}/history")
            ->assertSuccessful()
            ->json();

        $this->assertSame(1, $history['summary']['competitions']);
        $this->assertSame('competition', $history['sessions'][0]['kind']);
        $this->assertSame(3, $history['sessions'][0]['correct']);
        $this->assertSame(1, $history['sessions'][0]['rank']);
        $this->assertSame(1, $history['sessions'][0]['participants']);
        $this->assertStringContainsString('Maktab', $history['sessions'][0]['title']);

        // The student reads the same record; a stranger teacher does not.
        $mine = $this->as(800)->getJson('/api/history')->assertSuccessful()->json();
        $this->assertSame(3, $mine['sessions'][0]['correct']);

        $this->as(701, 'Boshqa')->postJson('/api/me/role', ['role' => 'teacher']);
        $this->as(701)->getJson("/api/teacher/groups/{$class['group']}/students/{$student->id}/history")->assertStatus(403);
    }
}
