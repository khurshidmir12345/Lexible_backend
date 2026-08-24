<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;

class TelegramSetup extends Command
{
    protected $signature = 'telegram:setup
        {--webhook-only : Only register the webhook}
        {--info         : Only show the current state}';

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

        $menu = $telegram->setChatMenuButton("🎮 O'ynash", config('telegram.mini_app.url'));
        $this->line(($menu['ok'] ?? false) ? '✅ Menyu tugmasi: '.config('telegram.mini_app.url') : '❌ Menyu: '.($menu['description'] ?? '—'));

        $this->newLine();
        $this->comment('Qoʼlda qilinadigan qadam: @BotFather → /newapp → short name "'.config('telegram.mini_app.short_name').'"');
        $this->comment('Shundan keyin toʼgʼridan-toʼgʼri havola ishlaydi: https://t.me/'.ltrim((string) config('telegram.username'), '@').'/'.config('telegram.mini_app.short_name'));

        return self::SUCCESS;
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
