<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Category;
use App\Models\GroupMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The heartbeat the open app polls: has anything on the map changed, and
 * how many notifications are waiting. A teacher adding a student, opening a
 * lesson or swapping a path used to reach the student only on the next cold
 * start; the app now notices within one poll and re-reads the road.
 *
 * Deliberately a handful of indexed aggregate queries — it is called every
 * twenty seconds by every open client.
 */
class PulseController extends Controller
{
    public function __invoke(Request $request): array
    {
        $userId = $request->user()->id;

        $categories = Category::where('user_id', $userId)
            ->selectRaw('COUNT(*) AS n, MAX(updated_at) AS at')
            ->first();

        $memberships = GroupMember::where('user_id', $userId)
            ->selectRaw('COUNT(*) AS n, MAX(updated_at) AS at, GROUP_CONCAT(status) AS statuses')
            ->first();

        // A class road also changes when the teacher writes a stage the
        // student has not received yet — those show up as placeholders.
        $stages = DB::table('path_stages')
            ->join('groups', 'groups.path_id', '=', 'path_stages.path_id')
            ->join('group_members', 'group_members.group_id', '=', 'groups.id')
            ->where('group_members.user_id', $userId)
            ->where('group_members.status', 'active')
            ->selectRaw('COUNT(*) AS n, MAX(path_stages.updated_at) AS at, SUM(path_stages.words_count) AS words')
            ->first();

        return [
            'road' => md5(implode('|', [
                $categories->n, $categories->at,
                $memberships->n, $memberships->at, $memberships->statuses,
                $stages->n, $stages->at, $stages->words,
            ])),
            'unread' => AppNotification::where('user_id', $userId)->where('is_read', false)->count(),
        ];
    }
}
