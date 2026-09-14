<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;

class TelegramSetup extends Command
{
    protected $signature = 'telegram:setup
        {--webhook-only : Only register the webhook}
        {--info         : Only show the current state}
        {--intro-to=    : Telegram id of the chat that receives the intro-video upload (default: the first registered user)}
        {--reupload     : Upload the intro video again even if a file_id is cached}';

    protected $description = 'Register the webhook, bot commands and Mini App menu button';

    public function handle(TelegramClient $telegram): int
    {
        $me = $telegram->getMe();

        if (! ($me['ok'] ?? false)) {
            $this->error('Bot tokeni ishlamayapti: '.($me['description'] ?? 'nomaʼlum xatolik'));

            return self::FAILURE;
        }

        $bot = $me['result'];
        $this->info("Bot: @{$bot['username']} ({$bot['first_name']})");

        \App\Support\MiniAppLink::refresh();

        if ($bot['has_main_web_app'] ?? false) {
            $this->info('✅ Main Mini App yoqilgan — havolalar t.me/'.$bot['username'].'?startapp=… koʼrinishida.');
        } else {
            $this->warn('⚠️  Main Mini App YOQILMAGAN — havolalar vaqtincha t.me/'.$bot['username'].'?start=… koʼrinishida (chat → Start → tugma). BotFather: /mybots → Bot Settings → Mini Apps → Enable Mini App.');
        }

        if ($this->option('info')) {
            return $this->showInfo($telegram);
        }

        $url = rtrim((string) config('app.url'), '/').'/'.ltrim(config('telegram.webhook.path'), '/');
        $secret = config('telegram.webhook.secret');

        if (blank($secret)) {
            $this->warn('TELEGRAM_WEBHOOK_SECRET boʼsh — istalgan odam soxta yangilanish yubora oladi.');
        }

        $result = $telegram->setWebhook($url, $secret, config('telegram.webhook.allowed_updates'));
        $this->line(($result['ok'] ?? false) ? "✅ Webhook: {$url}" : '❌ Webhook: '.($result['description'] ?? '—'));

        if ($this->option('webhook-only')) {
            return self::SUCCESS;
        }

        $commands = $telegram->setMyCommands([
            ['command' => 'start', 'description' => 'Botni ishga tushirish'],
            ['command' => 'play', 'description' => "O'yinni ochish"],
            ['command' => 'stats', 'description' => 'Statistikam'],
            ['command' => 'invite', 'description' => "Do'st taklif qilish"],
            ['command' => 'help', 'description' => 'Yordam'],
        ]);
        $this->line(($commands['ok'] ?? false) ? '✅ Buyruqlar oʼrnatildi' : '❌ Buyruqlar: '.($commands['description'] ?? '—'));

        $name = $telegram->setMyName(config('app.name'));
        $this->line(($name['ok'] ?? false) ? '✅ Bot nomi: '.config('app.name') : '❌ Bot nomi: '.($name['description'] ?? '—'));

        $description = $telegram->setMyDescription(implode("\n", [
            "🦊 Bayoz — ingliz tili so'zlarini o'yin orqali yodlang.",
            '',
            "Har kuni 5 daqiqa: yangi so'zlar, xazina xaritasi, do'stlar bilan duel.",
            "Boshlash uchun «Start» ni bosing 👇",
        ]));
        $this->line(($description['ok'] ?? false) ? '✅ Tavsif oʼrnatildi' : '❌ Tavsif: '.($description['description'] ?? '—'));

        $short = $telegram->setMyShortDescription("Ingliz tili so'zlarini o'yin orqali yodlash ilovasi 🦊");
        $this->line(($short['ok'] ?? false) ? '✅ Qisqa tavsif oʼrnatildi' : '❌ Qisqa tavsif: '.($short['description'] ?? '—'));

        $menu = $telegram->setChatMenuButton("🎮 O'ynash", config('telegram.mini_app.url'));
        $this->line(($menu['ok'] ?? false) ? '✅ Menyu tugmasi: '.config('telegram.mini_app.url') : '❌ Menyu: '.($menu['description'] ?? '—'));

        $this->uploadIntroVideo($telegram);

        $this->newLine();
        $this->comment('Qoʼlda qilinadigan qadam: @BotFather → /setuserpic → bot profil rasmi (backend/public/brand/bayoz-logo.png)');
        $this->comment('Qoʼlda qilinadigan qadam: @BotFather → /mybots → Bot Settings → Mini Apps → Enable Mini App (Main Mini App), URL: '.config('telegram.mini_app.url'));
        $this->comment('Shundan keyin toʼgʼridan-toʼgʼri havola ishlaydi: '.\App\Support\MiniAppLink::to('test').' (startapp qiymati ilova ichida start_param sifatida keladi)');

        return self::SUCCESS;
    }

    /**
     * The intro video is 23 MB — too big for a URL send — so it is uploaded
     * once here, to a real chat, and the file_id is cached for every /start.
     * Doing it at setup keeps the webhook fast for the first new player.
     */
    protected function uploadIntroVideo(TelegramClient $telegram): void
    {
        if (! \App\Support\IntroVideo::exists()) {
            $this->warn('⚠️  Intro video topilmadi: '.\App\Support\IntroVideo::path());

            return;
        }

        if ($this->option('reupload')) {
            \App\Support\IntroVideo::forget();
        }

        if (\App\Support\IntroVideo::fileId()) {
            $this->line('✅ Intro video allaqachon yuklangan (file_id keshda)');

            return;
        }

        $chatId = $this->option('intro-to')
            ?: \App\Models\User::whereNotNull('chat_id')->orderBy('id')->value('chat_id');

        if (! $chatId) {
            $this->warn('⚠️  Intro video yuklanmadi: chat topilmadi (--intro-to=<telegram id> bering)');

            return;
        }

        $this->line("⏫ Intro video yuklanmoqda (chat {$chatId})…");
        $sent = \App\Support\IntroVideo::send($telegram, $chatId, ['reply_markup' => \App\Support\BotKeyboard::play()]);

        $this->line(($sent['ok'] ?? false)
            ? '✅ Intro video yuklandi, file_id saqlandi'
            : '❌ Intro video: '.($sent['description'] ?? '—'));
    }

    protected function showInfo(TelegramClient $telegram): int
    {
        $info = $telegram->getWebhookInfo()['result'] ?? [];

        $this->table(['Maydon', 'Qiymat'], [
            ['URL', $info['url'] ?: '(oʼrnatilmagan)'],
            ['Kutayotgan yangilanishlar', $info['pending_update_count'] ?? 0],
            ['Maxfiy header', ($info['has_custom_certificate'] ?? false) ? 'sertifikat' : (blank(config('telegram.webhook.secret')) ? 'yoʼq' : 'bor')],
            ['Oxirgi xatolik', $info['last_error_message'] ?? '—'],
            ['Xatolik vaqti', isset($info['last_error_date']) ? date('Y-m-d H:i', $info['last_error_date']) : '—'],
            ['Ruxsat etilgan turlar', implode(', ', $info['allowed_updates'] ?? [])],
        ]);

        return self::SUCCESS;
    }
}
