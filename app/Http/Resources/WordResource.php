<?php

namespace App\Http\Resources;

use App\Models\Word;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Word */
class WordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = $request->user()?->native_lang ?? 'uz';

        return [
            'id' => $this->id,
            'en' => $this->word,
            'translation' => $this->translation($locale),
            'translations' => $this->acceptedAnswers($locale),
            'pos' => $this->part_of_speech,
            'transcription' => $this->transcription,
            'audio' => $this->audio_url,
            'emoji' => $this->emoji,
            'icon' => $this->icon_url,
            'icon_large' => $this->icon_large_url,
            'level' => $this->cefr_level,
            'definition' => $this->definition[$locale] ?? $this->definition['en'] ?? null,
            'example' => $this->example['en'] ?? null,
            'example_translation' => $this->example[$locale] ?? null,
            'mastery' => $this->whenLoaded('progress', function () {
                $progress = $this->progress->first();

                return $progress ? $progress->mastery() + ['overall' => $progress->overall] : null;
            }),
        ];
    }
}
