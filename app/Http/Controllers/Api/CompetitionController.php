<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Services\Game\CompetitionService;
use App\Services\Game\ResultCard;
use App\Services\Game\TestBuilder;
use App\Services\Telegram\TelegramClient;
use App\Support\MiniAppLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** The student side of a contest: join, wait, play, see the board. */
class CompetitionController extends Controller
{
    public function __construct(
        protected CompetitionService $competitions,
        protected TestBuilder $builder,
    ) {}

    public function show(Request $request, string $code): array
    {
        $competition = $this->find($code);

        return ['competition' => $this->competitions->studentState($competition, $request->user())];
    }

    public function join(Request $request, string $code): array
    {
        $competition = $this->find($code);

        abort_if($competition->expires_at?->isPast() && $competition->status === 'lobby',
            Response::HTTP_GONE, 'Bu musobaqa muddati tugagan.');

        $this->competitions->join($competition, $request->user());

        return ['competition' => $this->competitions->studentState($competition->fresh(), $request->user())];
    }

    /** Hands over the question paper once the teacher has started the round. */
    public function session(Request $request, string $code): array
    {
        $competition = $this->find($code);
        $session = $this->competitions->session($competition, $request->user());

        return [
            'session_id' => $session->id,
            'questions' => $this->builder->forClient($session->payload),
            'competition' => $this->competitions->studentState($competition->fresh(), $request->user()),
        ];
    }

    public function finish(Request $request, string $code): array
    {
        $competition = $this->find($code);

        $data = $request->validate([
            'score' => ['required', 'integer', 'min:0'],
            'total' => ['required', 'integer', 'min:0'],
            'duration_ms' => ['required', 'integer', 'min:0'],
        ]);

        $competition = $this->competitions->finish(
            $competition, $request->user(), $data['score'], $data['total'], $data['duration_ms'],
        );

        return ['competition' => $this->competitions->studentState($competition, $request->user())];
    }

    /**
     * The board as a picture, ready to drop into the class group.
     *
     * Telegram is handed the picture as a prepared inline message; the app
     * then opens the chat picker with `WebApp.shareMessage`. A client too old
     * for that asks for `mode=chat` instead and the bot sends the picture to
     * the player's own chat, to be forwarded by hand.
     */
    public function share(Request $request, string $code, ResultCard $card, TelegramClient $telegram): array
    {
        $competition = $this->find($code);
        $user = $request->user();

        abort_unless(
            $competition->teacher_id === $user->id
                || $competition->players()->where('user_id', $user->id)->exists(),
            Response::HTTP_FORBIDDEN,
            'Bu musobaqa sizniki emas.',
        );

        $data = $request->validate(['mode' => ['nullable', 'in:share,chat']]);

        $board = $this->competitions->results($competition);
        $path = $card->render($board);
        $url = Storage::disk('public')->url($path);

        $winner = $board['podium'][0] ?? null;
        $caption = '🏆 <b>'.e($board['group']).'</b>'
            .(! empty($board['stage']) ? ' · '.$board['stage'].'-bosqich' : '')
            .' musobaqasi'
            .($winner ? "\nGʼolib: <b>".e($winner['name'])."</b> — {$winner['score']}/{$winner['total']}" : '');

        $reply = ['prepared_message_id' => null, 'image_url' => $url, 'sent' => false];

        if (blank(config('telegram.token'))) {
            return $reply;
        }

        $keyboard = ['inline_keyboard' => [[
            ['text' => '🎮 Lexible’da oʼynash', 'url' => MiniAppLink::to('comp_'.$competition->code)],
        ]]];

        if (($data['mode'] ?? 'share') === 'chat') {
            $result = $telegram->sendPhoto($user->chat_id ?: $user->telegram_id, $url, $caption, ['reply_markup' => $keyboard]);
            $reply['sent'] = (bool) ($result['ok'] ?? false);

            return $reply;
        }

        $result = $telegram->savePreparedInlineMessage((int) $user->telegram_id, [
            'type' => 'photo',
            'id' => substr(md5($path.$user->id), 0, 32),
            'photo_url' => $url,
            'thumbnail_url' => $url,
            'caption' => $caption,
            'parse_mode' => 'HTML',
            'reply_markup' => $keyboard,
        ]);

        $reply['prepared_message_id'] = $result['result']['id'] ?? null;

        return $reply;
    }

    public function results(Request $request, string $code): array
    {
        $competition = $this->find($code);

        abort_unless(
            $competition->players()->where('user_id', $request->user()->id)->exists(),
            Response::HTTP_FORBIDDEN,
        );

        return ['competition' => $this->competitions->results($competition)];
    }

    protected function find(string $code): Competition
    {
        return Competition::where('code', strtoupper($code))->firstOrFail();
    }
}
