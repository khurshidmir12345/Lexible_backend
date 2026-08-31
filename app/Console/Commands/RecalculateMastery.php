<?php

namespace App\Console\Commands;

use App\Models\Category;
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
        WordProgress::chunkById(500, function ($rows) use (&$words) {
            foreach ($rows as $progress) {
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
        Category::where('type', '!=', 'exam')->chunkById(200, function ($categories) use ($road, &$stages) {
            foreach ($categories as $category) {
                $road->refreshProgress($category);
                $stages++;
            }
        });
        $this->info("Bosqichlar yangilandi: {$stages}");

        User::query()->chunkById(200, function ($users) {
            foreach ($users as $user) {
                $learned = WordProgress::where('user_id', $user->id)->where('is_learned', true)->count();

                if ($user->words_learned !== $learned) {
                    $user->update(['words_learned' => $learned]);
                }
            }
        });
        $this->info('Oʼrganilgan-soʼz hisoblagichlari tekshirildi.');

        return self::SUCCESS;
    }
}
