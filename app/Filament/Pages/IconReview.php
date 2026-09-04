<?php

namespace App\Filament\Pages;

use App\Models\Icon;
use App\Models\IconCandidate;
use App\Models\Word;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Hand-checking the pictures the matcher chose.
 *
 * The left column lists words (most frequent first) with their current icon;
 * the right column shows the selected word with the matcher's shortlist and a
 * library search. Every decision is stored with `icon_source = manual`, which
 * the automatic `icons:assign` never overwrites — so the reviewed layer only
 * grows. A "no picture" verdict is a manual row with a null icon.
 */
class IconReview extends Page
{
    use WithPagination;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|\UnitEnum|null $navigationGroup = 'Lugʼat';

    protected static ?string $navigationLabel = 'Ikonkalar';

    protected static ?string $title = 'Ikonkalarni koʼrib chiqish';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'icons';

    protected string $view = 'filament.pages.icon-review';

    public const PER_PAGE = 40;

    /** How deep into the frequency list the review is expected to go. */
    public const REVIEW_DEPTH = 3000;

    #[Url]
    public string $search = '';

    /** pending | none | low | manual | all */
    #[Url]
    public string $status = 'pending';

    #[Url]
    public int $maxRank = self::REVIEW_DEPTH;

    #[Url]
    public ?int $selectedId = null;

    public string $iconQuery = '';

    /**
     * Words decided during this visit. They would drop out of the "pending"
     * list at once, which looked like the word vanishing — so they stay in
     * place, marked as done, until the filter or page changes.
     *
     * @var list<int>
     */
    public array $decided = [];

    public static function getNavigationBadge(): ?string
    {
        $pending = static::pendingQuery()->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    /** Words in the review depth that no admin has looked at yet. */
    protected static function pendingQuery(): Builder
    {
        return Word::query()
            ->where('is_teachable', true)
            ->where('is_active', true)
            ->whereNotNull('frequency_rank')
            ->where('frequency_rank', '<=', self::REVIEW_DEPTH)
            ->where(fn (Builder $q) => $q->whereNull('icon_source')->orWhere('icon_source', '!=', 'manual'));
    }

    public function getMaxContentWidth(): \Filament\Support\Enums\Width|string|null
    {
        return \Filament\Support\Enums\Width::Full;
    }

    /* ------------------------------------------------------------ filters */

    public function updatedSearch(): void
    {
        $this->resetList();
    }

    public function updatedStatus(): void
    {
        $this->resetList();
    }

    public function updatedMaxRank(): void
    {
        $this->resetList();
    }

    public function updatedPaginators(): void
    {
        $this->decided = [];
        $this->selectedId = null;
    }

    protected function resetList(): void
    {
        $this->resetPage();
        $this->decided = [];
        $this->selectedId = null;
    }

    protected function baseQuery(): Builder
    {
        $query = Word::query()
            ->with('icon')
            ->where('is_teachable', true)
            ->where('is_active', true)
            ->whereNotNull('frequency_rank')
            ->where('frequency_rank', '<=', max(1, $this->maxRank))
            ->orderBy('frequency_rank');

        $notManual = fn (Builder $q) => $q->whereNull('icon_source')->orWhere('icon_source', '!=', 'manual');

        $status = match ($this->status) {
            'pending' => fn (Builder $q) => $q->where($notManual),
            'none' => fn (Builder $q) => $q->whereNull('icon_id')->where($notManual),
            'low' => fn (Builder $q) => $q->whereNotNull('icon_id')->where($notManual)->where('icon_confidence', '<', 80),
            'manual' => fn (Builder $q) => $q->where('icon_source', 'manual'),
            default => null,
        };

        if ($status) {
            $query->where(fn (Builder $q) => $q->where($status)
                ->when($this->decided !== [], fn (Builder $q) => $q->orWhereIn('id', $this->decided)));
        }

        if ($term = mb_strtolower(trim($this->search))) {
            $query->where('normalized', 'like', addcslashes($term, '%_\\').'%');
        }

        return $query;
    }

    #[Computed]
    public function words(): LengthAwarePaginator
    {
        return $this->baseQuery()->paginate(self::PER_PAGE);
    }

    /** Progress inside the current depth, regardless of the status filter. */
    #[Computed]
    public function progress(): array
    {
        $scope = Word::query()
            ->where('is_teachable', true)
            ->where('is_active', true)
            ->whereNotNull('frequency_rank')
            ->where('frequency_rank', '<=', max(1, $this->maxRank));

        $total = (clone $scope)->count();
        $manual = (clone $scope)->where('icon_source', 'manual')->count();

        return [
            'total' => $total,
            'manual' => $manual,
            'with_icon' => (clone $scope)->whereNotNull('icon_id')->count(),
            'percent' => $total ? (int) round($manual / $total * 100) : 0,
        ];
    }

    /* ---------------------------------------------------------- selection */

    #[Computed]
    public function selected(): ?Word
    {
        return $this->selectedId ? Word::with('icon')->find($this->selectedId) : null;
    }

    public function select(int $id): void
    {
        $this->selectedId = $id;
        $this->iconQuery = '';
    }

    /** Move the selection by one row on the current page (keyboard ↑/↓). */
    public function move(int $step): void
    {
        $ids = $this->words->getCollection()->pluck('id')->values();

        if ($ids->isEmpty()) {
            return;
        }

        $index = $ids->search($this->selectedId);
        $next = $index === false ? 0 : max(0, min($ids->count() - 1, $index + $step));
        $this->select($ids[$next]);
    }

    /** The decided word stays in its row (marked done); the next one is selected. */
    protected function advance(int $fromId): void
    {
        if (! in_array($fromId, $this->decided, true)) {
            $this->decided[] = $fromId;
        }

        unset($this->words);

        $ids = $this->words->getCollection()->pluck('id')->values();
        $index = $ids->search($fromId);

        if ($ids->isEmpty()) {
            $this->selectedId = null;

            return;
        }

        $this->select($ids[$index === false ? 0 : min($ids->count() - 1, $index + 1)]);
    }

    /* ---------------------------------------------------------- decisions */

    /** The current icon is right: freeze it. */
    public function approve(): void
    {
        $word = $this->selected;

        if (! $word || ! $word->icon_id) {
            return;
        }

        $word->update(['icon_source' => 'manual', 'icon_confidence' => 100]);
        $this->decided($word);
    }

    /** No picture fits this word — and that verdict is final for the matcher. */
    public function reject(): void
    {
        $word = $this->selected;

        if (! $word) {
            return;
        }

        $word->update(['icon_id' => null, 'icon_path' => null, 'icon_source' => 'manual', 'icon_confidence' => null]);
        $this->decided($word);
    }

    public function pick(string $slug): void
    {
        $word = $this->selected;
        $icon = Icon::query()->where('slug', $slug)->first();

        if (! $word || ! $icon) {
            return;
        }

        $word->update([
            'icon_id' => $icon->id,
            'icon_path' => Icon::pathFor($icon->slug),
            'icon_source' => 'manual',
            'icon_confidence' => 100,
        ]);
        $this->decided($word);
    }

    /** Keyboard 1-9: pick the n-th suggestion. */
    public function pickIndex(int $index): void
    {
        $slug = $this->candidates->pluck('slug')->get($index);

        if ($slug) {
            $this->pick($slug);
        }
    }

    /** Hand a decided word back to the machine (undo). */
    public function release(): void
    {
        $word = $this->selected;

        if (! $word) {
            return;
        }

        $word->update(['icon_source' => $word->icon_id ? 'llm' : null]);
        unset($this->selected, $this->words, $this->progress);
    }

    protected function decided(Word $word): void
    {
        unset($this->selected, $this->progress);
        $this->advance($word->id);
    }

    /* --------------------------------------------------------- candidates */

    /**
     * The matcher's shortlist for the selected word (best first), with the
     * current icon pinned to the front. A library search replaces the list.
     *
     * @return Collection<int, Icon>
     */
    #[Computed]
    public function candidates(): Collection
    {
        $word = $this->selected;

        if (! $word) {
            return collect();
        }

        if ($term = mb_strtolower(trim($this->iconQuery))) {
            $like = '%'.addcslashes($term, '%_\\').'%';

            return Icon::query()
                ->where(fn (Builder $q) => $q
                    ->where('title', 'like', $like)
                    ->orWhere('slug', 'like', $like)
                    ->orWhere('tags', 'like', $like))
                ->orderByRaw('CASE WHEN LOWER(title) = ? THEN 0 ELSE 1 END', [$term])
                ->orderByRaw('LENGTH(title)')
                ->limit(36)
                ->get();
        }

        $slugs = IconCandidate::slugsFor($word->normalized);

        if ($word->icon) {
            $slugs = array_values(array_unique([$word->icon->slug, ...$slugs]));
        }

        if ($slugs === []) {
            return collect();
        }

        $bySlug = Icon::query()->whereIn('slug', $slugs)->get()->keyBy('slug');

        return collect($slugs)->map(fn ($slug) => $bySlug->get($slug))->filter()->values();
    }

    public function updatedIconQuery(): void
    {
        unset($this->candidates);
    }
}
