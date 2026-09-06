<?php

namespace App\Services\Game;

use App\Jobs\SendTelegramMessage;
use App\Models\AppNotification;
use App\Models\User;
use App\Support\BotKeyboard;

/**
 * Everything the app tells a player unasked.
 *
 * Each event writes a row to the in-app bell feed and, when the player can
 * be reached, queues the same news as a bot message with one button back
 * into the game. The feed stays a record of the player's own progress; the
 * bot message is what actually brings them back.
 */
class NotificationService
{
    public function push(User|int $user, string $type, string $title, ?string $body = null, ?string $emoji = null, array $data = []): AppNotification
    {
        return AppNotification::create([
            'user_id' => $user instanceof User ? $user->id : $user,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'emoji' => $emoji,
            'data' => $data ?: null,
            'created_at' => now(),
        ]);
    }

    /** Queue a bot message; the job itself skips players who cannot be reached. */
    public function message(User|int $user, string $text, ?array $keyboard = null): void
    {
        SendTelegramMessage::dispatch($user instanceof User ? $user->id : $user, $text, $keyboard);
    }

    /* ------------------------------------------------------------ progress */

    public function stageUnlocked(User|int $user, string $title): AppNotification
    {
        $this->message($user,
            "🎉 <b>Yangi bosqich ochildi!</b>\n\n«{$title}» sizni kutmoqda. Yangi soʼzlar — yangi gʼalabalar.",
            BotKeyboard::play('🚀 Bosqichni boshlash'),
        );

        return $this->push($user, 'unlock', 'Yangi bosqich ochildi', "«{$title}» ochildi", '🎉');
    }

    public function streak(User|int $user, int $days): AppNotification
    {
        $this->message($user,
            "🔥 <b>{$days} kunlik seriya!</b>\n\nHar kuni bir qadam — mana shu sizni oldinga olib boradi. Ajoyib ish!",
        );

        return $this->push($user, 'streak', "{$days} kunlik seriya!", 'Ajoyib, surʼatni saqlang', '🔥');
    }

    /* --------------------------------------------------------------- duels */

    /** The invited friend arrived — the host's game can begin. */
    public function duelJoined(User|int $host, string $guest, string $category): AppNotification
    {
        $this->message($host,
            "⚔️ <b>{$guest} duelni qabul qildi!</b>\n\n«{$category}» boʼyicha bellashuv boshlanmoqda. Kim tezroq?",
            BotKeyboard::play('⚔️ Duelga kirish'),
        );

        return $this->push($host, 'duel', "{$guest} duelni qabul qildi", "«{$category}» — bellashuv boshlanmoqda", '⚔️');
    }

    public function duelFinished(User|int $user, bool $won, string $rival, int $mine, int $theirs): AppNotification
    {
        $this->message($user, $won
            ? "🏆 <b>Duelda gʼalaba — {$mine}:{$theirs}!</b>\n\n{$rival} ustidan gʼalaba qozondingiz. Zoʼr!"
            : "⚔️ <b>Duel yakunlandi — {$mine}:{$theirs}</b>\n\n{$rival} bu safar tezroq boʼldi. Revansh?",
            BotKeyboard::play($won ? '🎮 Davom etish' : '🔁 Revansh'),
        );

        return $won
            ? $this->push($user, 'duel', "Duelda gʼalaba — {$mine}:{$theirs}", "{$rival} ustidan gʼalaba qozondingiz", '🏆')
            : $this->push($user, 'duel', "Duelda magʼlubiyat — {$mine}:{$theirs}", "{$rival} bu safar tezroq boʼldi", '⚔️');
    }

    /* ------------------------------------------------------------- teacher */

    public function joinRequest(User|int $teacher, string $student, string $group): AppNotification
    {
        $this->message($teacher,
            "📨 <b>Yangi qoʼshilish soʼrovi</b>\n\n{$student} «{$group}» guruhiga qoʼshilmoqchi. Tasdiqlash ilovada.",
            BotKeyboard::play('👩‍🏫 Guruhni ochish'),
        );

        return $this->push($teacher, 'teacher', 'Yangi qoʼshilish soʼrovi', "{$student} — «{$group}»", '📨');
    }

    public function joinedGroup(User|int $user, string $group, string $teacher): AppNotification
    {
        $this->message($user,
            "👩‍🏫 <b>Ustoz sizni qabul qildi!</b>\n\n{$teacher} — «{$group}» guruhiga qoʼshildingiz. Birinchi bosqich xaritangizda.",
            BotKeyboard::play('🗺 Xaritani ochish'),
        );

        return $this->push($user, 'teacher', 'Ustoz soʼrovi tasdiqlandi', "{$teacher} — «{$group}» guruhiga qoʼshildingiz", '👩‍🏫');
    }

    /** The teacher attached (or swapped) the group's path. */
    public function pathAssigned(User|int $student, string $teacher, string $group, string $path, int $stages): AppNotification
    {
        $this->message($student,
            "🗺 <b>Yangi yoʼl tayinlandi!</b>\n\n{$teacher} «{$group}» guruhi uchun «{$path}» yoʼlini ochdi — {$stages} bosqich. Birinchisi hozir ochiq, boshlaymizmi?",
            BotKeyboard::play('🚀 Boshlash'),
        );

        return $this->push($student, 'teacher', 'Yangi yoʼl tayinlandi', "{$teacher} — «{$path}», {$stages} bosqich", '🗺');
    }

    /* --------------------------------------------------------- competitions */

    /** A lobby opened for the class — everyone on the roster is called in. */
    public function competitionOpened(User|int $student, string $teacher, string $where, string $code): AppNotification
    {
        $this->message($student,
            "🏁 <b>Musobaqa boshlanmoqda!</b>\n\n{$teacher} «{$where}» boʼyicha bellashuv ochdi. Sinfdoshlaringiz yigʼilmoqda — qoʼshiling!",
            BotKeyboard::play('🏆 Qoʼshilish', "comp_{$code}"),
        );

        return $this->push($student, 'competition', 'Musobaqaga taklif', "{$teacher} — «{$where}»", '🏁', ['startapp' => "comp_{$code}"]);
    }

    public function competitionStarted(User|int $user, string $group, ?string $code = null): AppNotification
    {
        $this->message($user,
            "🏁 <b>Start berildi!</b>\n\n«{$group}» — savollar tayyor, hamma bir vaqtda boshlaydi. Omad!",
            BotKeyboard::play('🏆 Oʼynash', $code ? "comp_{$code}" : null),
        );

        return $this->push($user, 'competition', 'Musobaqa boshlandi!', "«{$group}» — savollar tayyor", '🏁');
    }

    public function competitionFinished(User|int $user, int $rank, int $of): AppNotification
    {
        $place = $rank > 0 ? "{$rank}-oʼrin" : 'Yakunlandi';
        $line = match (true) {
            $rank === 1 => '🥇 Birinchi oʼrin — tabriklaymiz, chempion!',
            $rank === 2 => '🥈 Ikkinchi oʼrin — zoʼr natija!',
            $rank === 3 => '🥉 Uchinchi oʼrin — kuchli oʼyin!',
            $rank > 0 => "{$place}. Keyingi safar yanada yuqoriroq!",
            default => 'Natijalar ilovada.',
        };

        $this->message($user,
            "🏁 <b>Musobaqa yakunlandi</b>\n\n{$line}".($of > 0 ? "\n{$of} ishtirokchi orasida." : ''),
            BotKeyboard::play('📊 Natijalar'),
        );

        return $this->push($user, 'competition', "Musobaqa yakunlandi — {$place}",
            $of > 0 ? "{$of} ishtirokchi orasida" : null, $rank === 1 ? '🥇' : '🏁');
    }

    /* ----------------------------------------------------------- reminders */

    /** The daily "time to study" nudge, at the hour the player chose. */
    public function dailyReminder(User $user): AppNotification
    {
        $name = $user->first_name ?: 'doʼstim';
        $goal = max(1, (int) $user->daily_goal);
        $streak = (int) $user->streak_days;

        $openers = [
            "Salom, {$name}! 👋",
            "Hayrli kun, {$name}! ☀️",
            "{$name}, yodlash vaqti keldi ⏰",
            "Assalomu alaykum, {$name}! 🌱",
            "{$name}, besh daqiqa topiladimi? 🙂",
        ];

        $bodies = [
            "Bugungi <b>{$goal} ta soʼz</b> sizni kutmoqda. Bitta kichik qadam — katta natija.",
            "Har kuni {$goal} ta soʼz — bir yilda mingdan ortiq. Bugungi ulushni olaylikmi?",
            "Miyaga eng yaxshi mashq — yangi soʼz. Bugun {$goal} tasi tayyor turibdi.",
            "Kechagi soʼzlar hali xotirada — mustahkamlash uchun eng yaxshi vaqt hozir. {$goal} ta soʼz, 5 daqiqa.",
            "Ingliz tili — kunlik odat. Bugungi {$goal} ta soʼz bilan odatni davom ettiring.",
            "Yodlaganingizni takrorlash — bilimni saqlashning yagona yoʼli. Bugun {$goal} ta soʼz.",
        ];

        $tail = match (true) {
            $streak >= 7 => "\n\n🔥 <b>{$streak} kunlik seriya</b> — bugun uni ".($streak + 1).' qiling!',
            $streak >= 2 => "\n\n🔥 Seriyangiz {$streak} kun. Toʼxtamang!",
            default => '',
        };

        $seed = crc32($user->id.today()->toDateString());
        $text = $openers[$seed % count($openers)]."\n\n".$bodies[($seed >> 3) % count($bodies)].$tail;

        $this->message($user, $text, BotKeyboard::play('🎮 Boshlash'));

        return $this->push($user, 'reminder', 'Yodlash vaqti keldi', "Bugungi {$goal} ta soʼz sizni kutmoqda", '⏰');
    }

    /** Evening warning: yesterday's streak ends tonight unless they play. */
    public function streakAtRisk(User $user): AppNotification
    {
        $name = $user->first_name ?: 'doʼstim';
        $streak = (int) $user->streak_days;

        $this->message($user,
            "🔥 <b>{$name}, {$streak} kunlik seriyangiz xavf ostida!</b>\n\nBugun hali oʼynamadingiz. Bir kichik mashq — va seriya ".($streak + 1)." kunga aylanadi. 5 daqiqa kifoya.",
            BotKeyboard::play('🔥 Seriyani saqlash'),
        );

        return $this->push($user, 'streak', 'Seriya xavf ostida', "{$streak} kunlik seriyani saqlash uchun bugun oʼynang", '🔥');
    }
}
