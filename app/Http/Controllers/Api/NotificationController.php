<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /** The feed, grouped the way the screen shows it: today, yesterday, older. */
    public function index(Request $request): array
    {
        $items = AppNotification::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->limit(60)
            ->get();

        return [
            'unread' => $items->where('is_read', false)->count(),
            'groups' => $items
                ->groupBy(fn (AppNotification $n) => $this->bucket($n))
                ->map(fn ($group, $label) => [
                    'label' => $label,
                    'items' => $group->map(fn (AppNotification $n) => [
                        'id' => $n->id,
                        'type' => $n->type,
                        'title' => $n->title,
                        'body' => $n->body,
                        'emoji' => $n->emoji ?? '🔔',
                        'is_read' => $n->is_read,
                        'ago' => $this->ago($n->created_at),
                    ])->values(),
                ])
                ->values(),
        ];
    }

    public function markRead(Request $request): array
    {
        AppNotification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return ['unread' => 0];
    }

    protected function bucket(AppNotification $n): string
    {
        return match (true) {
            $n->created_at?->isToday() => 'BUGUN',
            $n->created_at?->isYesterday() => 'KECHA',
            default => 'AVVALROQ',
        };
    }

    /** Short relative time, in Uzbek, without leaning on a locale package. */
    protected function ago(?\DateTimeInterface $at): string
    {
        if (! $at) {
            return '';
        }

        $minutes = (int) round(abs(now()->diffInMinutes($at)));

        return match (true) {
            $minutes < 1 => 'hozir',
            $minutes < 60 => "{$minutes} daq",
            $minutes < 1440 => floor($minutes / 60).' soat',
            $minutes < 2880 => 'kecha',
            default => floor($minutes / 1440).' kun',
        };
    }
}
