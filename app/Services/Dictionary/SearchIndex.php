<?php

namespace App\Services\Dictionary;

use App\Models\Word;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Flat prefix index over headwords and translations (see the migration).
 *
 * Every write path that changes a word's text, translations or active flag
 * has to keep it current: single saves do so through the model hook, bulk
 * writers (the translation batches, the importer) run `words:index` after.
 */
class SearchIndex
{
    public const TABLE = 'word_search_terms';

    /** Rank given to words without a frequency, so they sort after known ones. */
    protected const UNRANKED = 999_999_999;

    protected const CACHE_TTL = 600;

    /**
     * Word ids matching a prefix, best first: an exact hit on the term, then
     * the most frequent word. A word is listed once even when several of its
     * terms match.
     *
     * @return list<int>
     */
    public function ids(string $query, string $locale, int $limit = 30): array
    {
        $needle = Str::lower(trim($query));
        $key = 'wsearch:'.$this->version().":{$locale}:{$limit}:".md5($needle);

        return Cache::remember($key, self::CACHE_TTL, function () use ($needle, $locale, $limit) {
            if ($needle === '') {
                return DB::table(self::TABLE)
                    ->where('locale', $locale)
                    ->where('kind', 'en')
                    ->orderBy('rank')
                    ->limit($limit)
                    ->pluck('word_id')
                    ->all();
            }

            $pattern = addcslashes($needle, '%_\\').'%';

            // Over-fetch, then collapse duplicates (a word whose headword and
            // translation both match) in PHP — cheaper than GROUP BY on the
            // index range.
            $rows = DB::table(self::TABLE)
                ->select('word_id')
                ->selectRaw('CASE WHEN term = ? THEN 0 ELSE 1 END AS inexact', [$needle])
                ->where('locale', $locale)
                ->where('term', 'like', $pattern)
                ->orderBy('inexact')
                ->orderBy('rank')
                ->limit($limit * 3)
                ->get();

            $ids = [];
            foreach ($rows as $row) {
                $ids[$row->word_id] = true;
                if (count($ids) >= $limit) {
                    break;
                }
            }

            return array_keys($ids);
        });
    }

    /** The matching words, in search order. */
    public function search(string $query, string $locale, int $limit = 30): Collection
    {
        $ids = $this->ids($query, $locale, $limit);

        if ($ids === []) {
            return new Collection;
        }

        $words = Word::whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn ($id) => $words->get($id))->filter()->values();
    }

    /** Re-index one word after it changed. */
    public function rebuild(Word $word): void
    {
        DB::table(self::TABLE)->where('word_id', $word->id)->delete();

        if ($rows = $this->rows($word)) {
            DB::table(self::TABLE)->insert($rows);
        }

        $this->bumpVersion();
    }

    /**
     * Rebuild the whole index in place, chunk by chunk, so search keeps
     * working while it runs. Returns the number of words processed.
     */
    public function rebuildAll(?callable $tick = null, int $chunk = 1000): int
    {
        $done = 0;

        Word::query()->select([
            'id', 'word', 'normalized', 'translations', 'frequency_rank', 'is_active',
        ])->orderBy('id')->chunkById($chunk, function (Collection $words) use (&$done, $tick) {
            $rows = [];
            foreach ($words as $word) {
                array_push($rows, ...$this->rows($word));
            }

            DB::transaction(function () use ($words, $rows) {
                DB::table(self::TABLE)->whereIn('word_id', $words->pluck('id'))->delete();
                foreach (array_chunk($rows, 2000) as $batch) {
                    DB::table(self::TABLE)->insert($batch);
                }
            });

            $done += $words->count();
            if ($tick) {
                $tick($words->count());
            }
        });

        $this->bumpVersion();

        return $done;
    }

    /** The rows one word contributes: none unless it is active and translated. */
    protected function rows(Word $word): array
    {
        if (! $word->is_active || ! is_array($word->translations)) {
            return [];
        }

        $rank = $word->frequency_rank ?: self::UNRANKED;
        $headword = Str::lower(trim((string) ($word->normalized ?: $word->word)));
        $rows = [];

        foreach ($word->translations as $locale => $values) {
            $terms = collect(is_array($values) ? $values : [$values])
                ->map(fn ($v) => Str::lower(trim((string) $v)))
                ->filter(fn ($v) => $v !== '')
                ->unique()
                ->values();

            if ($terms->isEmpty() || $headword === '') {
                continue;
            }

            $rows[] = $this->row($word->id, (string) $locale, 'en', $headword, $rank);

            foreach ($terms as $term) {
                $rows[] = $this->row($word->id, (string) $locale, 'tr', $term, $rank);
            }
        }

        return $rows;
    }

    protected function row(int $wordId, string $locale, string $kind, string $term, int $rank): array
    {
        return [
            'word_id' => $wordId,
            'locale' => Str::limit($locale, 4, ''),
            'kind' => $kind,
            'term' => Str::limit($term, 120, ''),
            'rank' => $rank,
        ];
    }

    /**
     * Cached result sets carry the index version in their key, so a re-index
     * (one word or all of them) makes every stale set unreachable at once.
     */
    protected function version(): int
    {
        return (int) Cache::get('wsearch:version', 1);
    }

    protected function bumpVersion(): void
    {
        Cache::forever('wsearch:version', $this->version() + 1);
    }
}
