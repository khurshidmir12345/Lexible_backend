<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Game\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * The reminder sweep. Runs every few minutes and asks, per timezone, two
 * questions: whose chosen hour has just arrived (and has not practised yet
 * today), and whose streak from yesterday is about to lapse this evening.
 *
 * Each player is marked with the local date they were reminded on, so the
 * sweep can run as often as it likes and still send once. A reminder more
 * than 90 minutes late (the worker was down) is skipped rather than sent
 * at an odd hour.
 *
 *   php artisan notify:reminders            # what the scheduler runs
 *   php artisan notify:reminders --dry      # list who would get what
 */
class SendReminders extends Command
{
    protected $signature = 'notify:reminders {--dry : Show the recipients without sending}';

    protected $description = 'Send the daily study reminders and streak warnings due right now';

    /** Study-day codes as stored in users.study_days, Monday first. */
    public const DAYS = ['Du', 'Se', 'Cho', 'Pa', 'Ju', 'Sha', 'Ya'];

    public const LATE_MINUTES = 90;

    public const STREAK_WARNING_FROM = '20:00';

    public const STREAK_WARNING_UNTIL = '21:45';

    public function handle(NotificationService $notifications): int
    {
        $dry = (bool) $this->option('dry');
        $sent = ['reminder' => 0, 'streak' => 0];

        foreach ($this->reachable()->distinct()->pluck('timezone') as $timezone) {
            $now = CarbonImmutable::now($timezone ?: 'Asia/Tashkent');
            $today = $now->toDateString();
            $dayCode = self::DAYS[$now->dayOfWeekIso - 1];

            // Daily reminder: chosen time has passed within the last 90 minutes.
            $due = $this->reachable()
                ->where('timezone', $timezone)
                ->whereNotNull('reminder_at')
                ->whereTime('reminder_at', '<=', $now->format('H:i:s'))
                ->whereTime('reminder_at', '>', $now->subMinutes(self::LATE_MINUTES)->format('H:i:s'))
                ->where(fn (Builder $q) => $q->whereNull('reminded_on')->orWhere('reminded_on', '<', $today))
                ->where(fn (Builder $q) => $q->whereNull('last_practiced_date')->orWhere('last_practiced_date', '<', $today))
                ->get()
                ->filter(fn (User $u) => $this->studiesOn($u, $dayCode));

            foreach ($due as $user) {
                $sent['reminder']++;
                $this->line("⏰ {$user->full_name} ({$timezone}, {$user->reminder_at})");

                if (! $dry) {
                    $user->update(['reminded_on' => $today]);
                    $notifications->dailyReminder($user);
                }
            }

            // Streak warning: played yesterday, not yet today, evening window.
            $clock = $now->format('H:i');

            if ($clock >= self::STREAK_WARNING_FROM && $clock <= self::STREAK_WARNING_UNTIL) {
                $atRisk = $this->reachable()
                    ->where('timezone', $timezone)
                    ->where('streak_days', '>=', 2)
                    ->whereDate('last_practiced_date', $now->subDay()->toDateString())
                    ->where(fn (Builder $q) => $q->whereNull('streak_warned_on')->orWhere('streak_warned_on', '<', $today))
                    ->get();

                foreach ($atRisk as $user) {
                    $sent['streak']++;
                    $this->line("🔥 {$user->full_name} ({$timezone}, {$user->streak_days} kun)");

                    if (! $dry) {
                        $user->update(['streak_warned_on' => $today]);
                        $notifications->streakAtRisk($user);
                    }
                }
            }
        }

        $this->info(($dry ? 'Yuborilardi' : 'Yuborildi').": eslatma {$sent['reminder']} · seriya {$sent['streak']}");

        return self::SUCCESS;
    }

    /** Players the bot can actually reach and who want to hear from it. */
    protected function reachable(): Builder
    {
        return User::query()
            ->where('onboarded', true)
            ->where('reminders_enabled', true)
            ->whereNotNull('chat_id')
            ->where('has_blocked_bot', false)
            ->where('is_banned', false)
            ->where('role', '!=', 'teacher');
    }

    /** An empty study-day list means every day. */
    protected function studiesOn(User $user, string $dayCode): bool
    {
        $days = $user->study_days ?? [];

        return $days === [] || in_array($dayCode, $days, true);
    }
}
