<?php

namespace App\Services\Game;

use App\Models\AppNotification;
use App\Models\User;

/**
 * The in-app bell feed.
 *
 * Notifications are written when something actually happens to the player —
 * a stage opens, a duel ends, a teacher approves them — so the feed is a
 * record of their own progress rather than marketing.
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

    public function stageUnlocked(User|int $user, string $title): AppNotification
    {
        return $this->push($user, 'unlock', 'Yangi bosqich ochildi', "«{$title}» ochildi", '🎉');
    }

    public function duelFinished(User|int $user, bool $won, string $rival, int $mine, int $theirs): AppNotification
    {
        return $won
            ? $this->push($user, 'duel', "Duelda gʼalaba — {$mine}:{$theirs}", "{$rival} ustidan gʼalaba qozondingiz", '🏆')
            : $this->push($user, 'duel', "Duelda magʼlubiyat — {$mine}:{$theirs}", "{$rival} bu safar tezroq boʼldi", '⚔️');
    }

    public function streak(User|int $user, int $days): AppNotification
    {
        return $this->push($user, 'streak', "{$days} kunlik seriya!", 'Ajoyib, surʼatni saqlang', '🔥');
    }

    public function joinedGroup(User|int $user, string $group, string $teacher): AppNotification
    {
        return $this->push($user, 'teacher', 'Ustoz soʼrovi tasdiqlandi', "{$teacher} — «{$group}» guruhiga qoʼshildingiz", '👩‍🏫');
    }

    public function competitionStarted(User|int $user, string $group): AppNotification
    {
        return $this->push($user, 'competition', 'Musobaqa boshlandi!', "«{$group}» — savollar tayyor", '🏁');
    }

    public function competitionFinished(User|int $user, int $rank, int $of): AppNotification
    {
        $place = $rank > 0 ? "{$rank}-oʼrin" : 'Yakunlandi';

        return $this->push($user, 'competition', "Musobaqa yakunlandi — {$place}",
            $of > 0 ? "{$of} ishtirokchi orasida" : null, $rank === 1 ? '🥇' : '🏁');
    }

    public function joinRequest(User|int $teacher, string $student, string $group): AppNotification
    {
        return $this->push($teacher, 'teacher', 'Yangi qoʼshilish soʼrovi', "{$student} — «{$group}»", '📨');
    }
}
