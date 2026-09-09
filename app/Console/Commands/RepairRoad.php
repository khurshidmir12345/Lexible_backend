<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Game\RoadMapService;
use Illuminate\Console\Command;

/**
 * Before 2026-09-09 the road was extended by raw position, so a player who
 * had joined a class got personal nodes typed by numbers the class stages
 * had used up — an exam every other node at the top of the map. This puts
 * the three-then-exam rhythm back for everyone.
 */
class RepairRoad extends Command
{
    protected $signature = 'road:repair {--user= : one player id}';

    protected $description = 'Re-read the exam rhythm of every personal road from its own node count';

    public function handle(RoadMapService $road): int
    {
        $users = User::query()
            ->when($this->option('user'), fn ($q, $id) => $q->whereKey($id))
            ->whereHas('categories')
            ->get();

        $fixed = 0;
        $skipped = 0;

        foreach ($users as $user) {
            $result = $road->repair($user);
            $fixed += $result['fixed'];
            $skipped += $result['skipped'];

            if ($result['fixed'] || $result['skipped']) {
                $this->line("  #{$user->id}: {$result['fixed']} ta tuzatildi, {$result['skipped']} ta qoldirildi");
            }
        }

        $this->info("{$users->count()} ta oʼyinchi koʼrildi — {$fixed} ta bosqich tuzatildi, {$skipped} ta qoʼl tegmadi");

        return self::SUCCESS;
    }
}
