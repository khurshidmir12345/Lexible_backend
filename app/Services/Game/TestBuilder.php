<?php

namespace App\Services\Game;

use App\Models\Category;
use App\Models\Word;
use Illuminate\Support\Collection;

/**
 * Turns a category plus the chosen exercise types into a question list.
 *
 * The correct answers stay on the server (in `test_sessions.payload`) and are
 * never sent to the client — the app receives only what it must display.
 */
class TestBuilder
{
    public function build(Category $category, array $types, Collection $words, string $locale): array
    {
        $questions = [];

        foreach ($types as $type) {
            $questions = array_merge($questions, match ($type) {
                'match' => $this->matchRounds($words, $locale),
                default => $words->shuffle()->map(fn (Word $w) => $this->question($type, $w, $words, $locale))->all(),
            });
        }

        return array_values(array_filter($questions));
    }

    protected function question(string $type, Word $word, Collection $pool, string $locale): ?array
    {
        $translation = $word->translation($locale);

        // Without a translation the word cannot be asked about in any mode.
        if (! $translation) {
            return null;
        }

        $base = [
            'id' => uniqid('q', true),
            'type' => $type,
            'word_id' => $word->id,
            'en' => $word->word,
            'translation' => $translation,
            'emoji' => $word->emoji,
            'icon' => $word->icon_path,
            'pos' => $word->part_of_speech,
            'transcription' => $word->transcription,
            'audio' => $word->audio_url,
        ];

        return match ($type) {
            'card' => $base,   // flashcard: the player self-reports

            'uz2en' => $base + [
                'prompt' => $translation,
                'options' => $this->options($word, $pool, $locale, fn (Word $w) => $w->word),
                'answer' => $word->word,
            ],

            'en2uz' => $base + [
                'prompt' => $word->word,
                'options' => $this->options($word, $pool, $locale, fn (Word $w) => $w->translation($locale)),
                'answer' => $translation,
            ],

            'spell' => $base + [
                'prompt' => $translation,
                'answer' => $word->word,
                'length' => mb_strlen($word->word),
            ],

            'image' => $this->imageQuestion($base, $word, $pool, $locale),

            default => null,
        };
    }

    /** The right answer plus decoys, shuffled. */
    protected function options(Word $word, Collection $pool, string $locale, callable $render): array
    {
        $values = $this->distractors($word, $pool, $locale)
            ->map($render)
            ->filter()
            ->push($render($word))
            ->unique()
            ->shuffle()
            ->values();

        return $values->all();
    }

    /**
     * Every word in the stage gets a picture question — the round must ask
     * all of them, not just the illustrated ones. A tile without a picture
     * falls back to its caption (the client shows 📘 plus the translation),
     * so a missing image never blocks the question; pictured decoys are
     * simply preferred to keep the grid as visual as possible.
     */
    protected function imageQuestion(array $base, Word $word, Collection $pool, string $locale): ?array
    {
        $needed = (int) config('game.session.choice_options');

        $decoys = $this->distractors($word, $pool, $locale)
            ->sortByDesc(fn (Word $w) => $this->pictured($w))
            ->take($needed - 1);

        // With no decoys at all there is nothing to choose between.
        if ($decoys->isEmpty()) {
            return null;
        }

        $options = $decoys
            ->push($word)
            ->unique('id')
            ->shuffle()
            ->map(fn (Word $w) => [
                'id' => $w->id,
                'emoji' => $w->emoji,
                'icon' => $w->icon_path,
                'label' => $w->translation($locale),
            ])
            ->values();

        return $base + ['prompt' => $word->word, 'options' => $options->all(), 'answer' => $word->id];
    }

    protected function pictured(Word $word): bool
    {
        return filled($word->emoji) || filled($word->icon_path);
    }

    /**
     * Decoys come from the same category first — words the player is studying
     * side by side are the ones worth telling apart. The dictionary fills in
     * when the category is too small.
     */
    protected function distractors(Word $word, Collection $pool, string $locale): Collection
    {
        $needed = config('game.session.choice_options') - 1;

        $local = $pool->where('id', '!=', $word->id)
            ->filter(fn (Word $w) => filled($w->translation($locale)))
            ->shuffle()
            ->take($needed);

        if ($local->count() >= $needed) {
            return $local;
        }

        $extra = Word::usable($locale)
            ->whereNotIn('id', $pool->pluck('id')->push($word->id))
            ->when($word->part_of_speech, fn ($q) => $q->where('part_of_speech', $word->part_of_speech))
            ->inRandomOrder()
            ->limit($needed - $local->count())
            ->get();

        return $local->concat($extra);
    }

    /** Matching is played in fixed-size rounds rather than one pair at a time. */
    protected function matchRounds(Collection $words, string $locale): array
    {
        $size = config('game.session.match_pairs');

        return $words
            ->filter(fn (Word $w) => filled($w->translation($locale)))
            ->shuffle()
            ->chunk($size)
            ->filter(fn (Collection $chunk) => $chunk->count() >= 2)   // a single pair is not a game
            ->map(fn (Collection $chunk) => [
                'id' => uniqid('m', true),
                'type' => 'match',
                'pairs' => $chunk->map(fn (Word $w) => [
                    'word_id' => $w->id,
                    'en' => $w->word,
                    'translation' => $w->translation($locale),
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Strips everything the client must not know yet.
     *
     * It is not enough to drop `answer`: for a "what is the English for X"
     * question the word itself, its transcription and its audio all give the
     * answer away, and in a picture question the word's own emoji does. Each
     * exercise therefore hides a different set of fields.
     */
    public function forClient(array $questions): array
    {
        $hidden = [
            'card' => [],
            'uz2en' => ['en', 'transcription', 'audio'],
            'en2uz' => ['translation'],
            'spell' => ['en', 'transcription', 'audio'],
            'image' => ['word_id', 'translation', 'emoji', 'icon', 'transcription', 'audio'],
            'match' => [],
        ];

        return array_map(function (array $q) use ($hidden) {
            unset($q['answer']);

            foreach ($hidden[$q['type']] ?? [] as $field) {
                unset($q[$field]);
            }

            // Picture options are identified by position, so the correct word's
            // id never travels to the client.
            if ($q['type'] === 'image') {
                $q['options'] = array_values(array_map(
                    fn (array $option, int $index) => [
                        'key' => $index,
                        'emoji' => $option['emoji'],
                        'icon' => $option['icon'],
                        'label' => $option['label'],
                    ],
                    $q['options'],
                    array_keys($q['options']),
                ));
            }

            return $q;
        }, $questions);
    }

    /** Grades one answer against the server-side key. */
    public function isCorrect(array $question, mixed $given): bool
    {
        return match ($question['type']) {
            'card' => (bool) $given,                       // the player self-reports
            'spell' => is_string($given)
                && mb_strtolower(trim($given)) === mb_strtolower($question['answer']),
            'image' => is_numeric($given)
                && ($question['options'][(int) $given]['id'] ?? null) === $question['answer'],
            'uz2en', 'en2uz' => is_string($given)
                && mb_strtolower(trim($given)) === mb_strtolower((string) $question['answer']),
            // A matching round counts as correct only when every pair was found
            // without a mistake.
            'match' => is_array($given)
                && $given !== []
                && collect($given)->every(fn ($pair) => (bool) ($pair['correct'] ?? false)),
            default => false,
        };
    }
}
