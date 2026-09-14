<?php

namespace App\Services\Telegram;

use App\Models\Setting;
use App\Models\User;
use App\Support\BotKeyboard;
use App\Support\IntroVideo;
use App\Support\MiniAppLink;
use Illuminate\Support\Str;

/**
 * Routes an incoming Bot API update. The bot itself stays intentionally thin:
 * it greets, explains, and hands the player over to the Mini App, where the
 * whole game lives.
 */
class UpdateHandler
{
    public function __construct(
        protected TelegramClient $telegram,
        protected PlayerResolver $players,
    ) {}

    public function handle(array $update): void
    {
        match (true) {
            isset($update['message']) => $this->onMessage($update['message']),
            isset($update['callback_query']) => $this->onCallbackQuery($update['callback_query']),
            isset($update['my_chat_member']) => $this->onChatMemberUpdate($update['my_chat_member']),
            default => null,
        };
    }

    protected function onMessage(array $message): void
    {
        if (empty($message['from']) || ($message['from']['is_bot'] ?? false)) {
            return;
        }

        $text = trim($message['text'] ?? '');
        $command = Str::lower(Str::before(Str::before($text, ' '), '@'));
        $payload = trim(Str::after($text, ' ')) === $text ? null : trim(Str::after($text, ' '));

        $user = $this->players->resolve(
            $message['from'],
            $message['chat']['id'] ?? null,
            $command === '/start' ? $payload : null,
        );

        app()->setLocale($user->native_lang);

        match ($command) {
            '/start' => $this->sendWelcome($user, $payload),
            '/play' => $this->sendPlay($user),
            '/stats', '/profile' => $this->sendStats($user),
            '/invite' => $this->sendInvite($user),
            '/help' => $this->sendHelp($user),
            default => $this->sendFallback($user),
        };
    }

    protected function onCallbackQuery(array $query): void
    {
        $this->telegram->answerCallbackQuery($query['id']);

        $chatId = $query['message']['chat']['id'] ?? $query['from']['id'] ?? null;

        if ($chatId && ($query['data'] ?? null) === IntroVideo::CALLBACK && IntroVideo::exists()) {
            IntroVideo::send($this->telegram, $chatId, ['reply_markup' => BotKeyboard::play()]);
        }
    }

    /** Fires when a player blocks or unblocks the bot — keeps broadcasts honest. */
    protected function onChatMemberUpdate(array $event): void
    {
        $status = $event['new_chat_member']['status'] ?? null;
        $telegramId = $event['from']['id'] ?? null;

        if (! $telegramId || ! in_array($status, ['kicked', 'member'], true)) {
            return;
        }

        User::where('telegram_id', $telegramId)->update([
            'has_blocked_bot' => $status === 'kicked',
        ]);
    }

    // ----------------------------------------------------------------- replies

    protected function sendWelcome(User $user, ?string $payload = null): void
    {
        $name = e($user->first_name ?: 'do\'stim');
        $extra = ['reply_markup' => $this->playKeyboard($payload)];

        // A player who already knows the bot and arrived through a duel or
        // class-game link wants the game, not the full greeting again.
        if (! $user->wasRecentlyCreated && $invite = $this->inviteText($payload)) {
            $this->telegram->sendMessage($user->chat_id, $invite, $extra);

            return;
        }

        $text = Setting::get('bot.welcome') ?? $this->defaultWelcome($name);

        // One message: the tutorial video on top, the greeting as its
        // caption, the play button under it. Without the video file the
        // logo stands in; if Telegram refuses the media (local dev, a
        // caption over 1024 chars) the plain text greeting still goes out.
        $sent = IntroVideo::exists()
            ? IntroVideo::send($this->telegram, $user->chat_id, $extra, $text)
            : $this->telegram->sendPhoto($user->chat_id, $this->logoUrl(), $text, $extra);

        if (! ($sent['ok'] ?? false)) {
            $this->telegram->sendMessage($user->chat_id, $text, $extra);
        }
    }

    protected function sendPlay(User $user): void
    {
        $this->telegram->sendMessage(
            $user->chat_id,
            "🎮 O'yinni boshlaymizmi? Pastdagi tugmani bosing.",
            ['reply_markup' => $this->playKeyboard()],
        );
    }

    protected function sendStats(User $user): void
    {
        $categories = $user->categories()->where('status', 'completed')->count();

        $this->telegram->sendMessage($user->chat_id, implode("\n", [
            "👤 <b>{$user->display_name}</b>",
            '',
            "🔥 Ketma-ket: <b>{$user->streak_days}</b> kun (eng yaxshisi: {$user->best_streak})",
            "📚 Yodlangan soʼzlar: <b>{$user->words_learned}</b>",
            "🗺 Tugatilgan bosqichlar: <b>{$categories}</b>",
            "🎯 Kunlik maqsad: <b>{$user->daily_goal}</b> soʼz",
        ]), ['reply_markup' => $this->playKeyboard()]);
    }

    protected function sendInvite(User $user): void
    {
        $link = $this->referralLink($user);

        $this->telegram->sendMessage($user->chat_id, implode("\n", [
            '🎁 <b>Do\'stlaringizni taklif qiling!</b>',
            '',
            'Har bir do\'stingiz uchun tanga va XP olasiz.',
            '',
            "🔗 {$link}",
        ]), [
            'reply_markup' => ['inline_keyboard' => [[[
                'text' => "📤 Do'stga yuborish",
                'url' => 'https://t.me/share/url?url='.urlencode($link),
                'style' => 'primary',
            ]]]],
        ]);
    }

    protected function sendHelp(User $user): void
    {
        $this->telegram->sendMessage($user->chat_id, implode("\n", [
            '❓ <b>Bayoz haqida</b>',
            '',
            'Bayoz — ingliz tili so\'zlarini o\'yin orqali yodlash ilovasi.',
            '',
            '/play — o\'yinni ochish',
            '/stats — statistikangiz',
            '/invite — do\'st taklif qilish',
        ]), ['reply_markup' => $this->withIntroButton($this->playKeyboard())]);
    }

    protected function sendFallback(User $user): void
    {
        $this->telegram->sendMessage(
            $user->chat_id,
            "Men buyruqlarni tushunmayman 🙂 O'yin ilova ichida — tugmani bosing.",
            ['reply_markup' => $this->playKeyboard()],
        );
    }

    // ----------------------------------------------------------------- helpers

    protected function playKeyboard(?string $startParam = null): array
    {
        // A duel or class-game invite that arrived as a plain /start deep
        // link still lands inside the game: the button URL carries the code,
        // and the app reads it when Telegram's own start_param is absent.
        if ($startParam && preg_match('/^(duel|comp)_[A-Za-z0-9]+$/', $startParam)) {
            $label = str_starts_with($startParam, 'duel_')
                ? "⚔️ Duelga qo'shilish"
                : "🏆 Bellashuvga qo'shilish";

            return BotKeyboard::play($label, $startParam);
        }

        return BotKeyboard::play();
    }

    /** Adds the "Qoʼllanma video" row under the play button when the video exists (used by /help). */
    protected function withIntroButton(array $keyboard): array
    {
        if (IntroVideo::exists()) {
            $keyboard['inline_keyboard'][] = [IntroVideo::button()];
        }

        return $keyboard;
    }

    protected function inviteText(?string $payload): ?string
    {
        return match (true) {
            $payload && str_starts_with($payload, 'duel_') => "⚔️ Sizni duelga chaqirishdi! Qoʼshilish uchun tugmani bosing 👇",
            $payload && str_starts_with($payload, 'comp_') => "🏆 Sizni bellashuvga taklif qilishdi! Qoʼshilish uchun tugmani bosing 👇",
            default => null,
        };
    }

    public function referralLink(User $user): string
    {
        return MiniAppLink::to("ref_{$user->telegram_id}");
    }

    /** Public URL of the brand logo Telegram downloads for the welcome photo. */
    public function logoUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/brand/bayoz-logo.png';
    }

    protected function defaultWelcome(string $name): string
    {
        return implode("\n", [
            '🦊 Bayoz’ga xush kelibsiz!',
            '',
            'Inglizcha so‘zlarni o‘yin orqali yodlang, ball to‘plang va do‘stlaringiz bilan bellashing. 🎯',
            '',
            '🎥 Bayoz’dan qanday foydalanishni bilish uchun yuqoridagi qisqa video qo‘llanmani ko‘rib chiqing.',
            '',
            '🎮 Tayyor bo‘lsangiz, O‘ynash tugmasini bosing!',
        ]);
    }
}
