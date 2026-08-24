<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\Game\RoadMapService;
use Illuminate\Http\Request;

class RoadController extends Controller
{
    public function __invoke(Request $request, RoadMapService $road): array
    {
        return [
            'nodes' => $road->forUser($request->user())
                ->map(fn (Category $c) => [
                    'id' => $c->id,
                    'position' => $c->position,
                    'title' => $c->title,
                    'type' => $c->type,
                    'status' => $c->status,
                    'progress' => $c->progress,
                    'words_count' => $c->words_count,
                    'date' => $c->unlock_date?->toDateString(),
                    'season' => $c->season(),
                    'practiced' => $c->practiced,
                ])
                ->values(),
        ];
    }
}
