<?php

namespace App\Services\Telegram;

use App\Models\Setting;
use App\Models\User;
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
            '/start' => $this->sendWelcome($user),
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

    protected function sendWelcome(User $user): void
    {
        $name = e($user->first_name ?: 'do\'stim');

        $this->telegram->sendMessage(
            $user->chat_id,
            Setting::get('bot.welcome') ?? $this->defaultWelcome($name),
            ['reply_markup' => $this->playKeyboard()],
        );
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
            ]]]],
        ]);
    }

    protected function sendHelp(User $user): void
    {
        $this->telegram->sendMessage($user->chat_id, implode("\n", [
            '❓ <b>Lexible haqida</b>',
            '',
            'Lexible — ingliz tili so\'zlarini o\'yin orqali yodlash ilovasi.',
            '',
            '/play — o\'yinni ochish',
            '/stats — statistikangiz',
            '/invite — do\'st taklif qilish',
        ]), ['reply_markup' => $this->playKeyboard()]);
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

    protected function playKeyboard(): array
    {
        return ['inline_keyboard' => [[[
            'text' => "🎮 O'ynash",
            'web_app' => ['url' => config('telegram.mini_app.url')],
        ]]]];
    }

    public function referralLink(User $user): string
    {
        $bot = ltrim((string) config('telegram.username'), '@');
        $short = config('telegram.mini_app.short_name');

        return "https://t.me/{$bot}/{$short}?startapp=ref_{$user->telegram_id}";
    }

    protected function defaultWelcome(string $name): string
    {
        return implode("\n", [
            "Salom, <b>{$name}</b>! 👋",
            '',
            "<b>Lexible</b> — ingliz tili so'zlarini o'yin orqali yodlaysiz.",
            'Har kuni 5 daqiqa — va lug\'atingiz o\'sib boradi. 🚀',
            '',
            "Boshlash uchun pastdagi tugmani bosing 👇",
        ]);
    }
}
