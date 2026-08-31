<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\TestAnswer;
use App\Models\User;
use App\Models\WordProgress;
use App\Services\Game\RoadMapService;
use Illuminate\Console\Command;

/**
 * One-off backfill for the overall-percent fix.
 *
 * The old formula divided a word's mastery by all six exercise types, so a
 * word drilled in one game type sat at 16% forever. Rows only recalculate on
 * the next answer, so everything stored has to be brought onto the new
 * average-of-practised formula once: word overalls, the learned flags and
 * counters that hang off them, and every stage's progress percent.
 */
class RecalculateMastery extends Command
{
    protected $signature = 'game:recalculate-mastery';

    protected $description = 'Recompute word overalls, learned counters and stage progress with the average-of-practised formula';

    public function handle(RoadMapService $road): int
    {
        $words = 0;
        WordProgress::chunkById(200, function ($rows) use (&$words) {
            foreach ($rows as $progress) {
                // Scores written under the old +20-per-answer scale can't be
                // mapped onto pass/fail, but the answer log can: the last
                // answer in each exercise type decides, exactly as it now
                // does live. Types never answered are left untouched.
                $lastByType = TestAnswer::where('user_id', $progress->user_id)
                    ->where('word_id', $progress->word_id)
                    ->orderBy('id')
                    ->get(['type', 'is_correct'])
                    ->groupBy('type');

                foreach (WordProgress::DIMENSIONS as $dimension) {
                    if ($answers = $lastByType->get($dimension)) {
                        $progress->{'m_'.$dimension} = $answers->last()->is_correct ? 100 : 0;
                    }
                }

                $progress->recalculate();

                if ($progress->isDirty()) {
                    $progress->save();
                    $words++;
                }
            }
        });
        $this->info("Soʼzlar qayta hisoblandi: {$words}");

        // Exam nodes keep the accuracy of the attempt that passed them — they
        // have no vocabulary, so refreshing would zero them out.
        $stages = 0;
        $completed = 0;
        Category::where('type', '!=', 'exam')->chunkById(200, function ($categories) use ($road, &$stages, &$completed) {
            foreach ($categories as $category) {
                $road->refreshProgress($category);
                $stages++;

                // The rescored numbers may put a stage over the finish line;
                // normally the end of a round checks this, so the backfill
                // has to as well or the road stays stuck at 100%.
                $category->refresh();

                if ($category->status === 'in_progress'
                    && $category->progress >= config('game.mastery.learned_at')
                    && $category->words_count >= config('game.road.min_words_to_complete')) {
                    $road->complete($category);
                    $completed++;
                }
            }
        });
        $this->info("Bosqichlar yangilandi: {$stages}, yopildi: {$completed}");

        User::query()->chunkById(200, function ($users) {
            foreach ($users as $user) {
                $learned = WordProgress::where('user_id', $user->id)->where('is_learned', true)->count();

                if ($user->words_learned !== $learned) {
                    $user->update(['words_learned' => $learned]);
                }
            }
        });
        $this->info('Oʼrganilgan-soʼz hisoblagichlari tekshirildi.');

        // Accounts created before the UZ-default fix carried Telegram's
        // language guess. Anyone who has not confirmed a language through
        // onboarding is moved onto the default they will now be shown.
        $moved = User::where('onboarded', false)
            ->where('role', 'student')
            ->where('native_lang', '!=', 'uz')
            ->update(['native_lang' => 'uz']);
        $this->info("Til UZ ga oʼtkazildi: {$moved}");

        return self::SUCCESS;
    }
}
