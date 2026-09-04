<x-filament-panels::page>
    @php
        $progress = $this->progress;
        $selected = $this->selected;
        $words = $this->words;
        $candidates = $this->candidates;
        $chip = fn (?int $c) => $c === null ? '' : ($c >= 90 ? 'ok' : ($c >= 75 ? 'mid' : 'low'));
    @endphp

    <style>
        .ir { --ir-bg: #fff; --ir-line: #e5e7eb; --ir-wash: #f5f7f5; --ir-muted: #6b7280; --ir-text: #111827;
              --ir-green: #17a45c; --ir-green-soft: #e9f7ef; --ir-amber: #b45309; --ir-amber-soft: #fef3c7;
              --ir-red: #b91c1c; --ir-red-soft: #fee2e2; color: var(--ir-text); }
        .dark .ir { --ir-bg: #18181b; --ir-line: #2a2a2f; --ir-wash: #202024; --ir-muted: #9ca3af; --ir-text: #f3f4f6;
              --ir-green-soft: #10361f; --ir-amber-soft: #3b2a08; --ir-red-soft: #3d1414; }
        .ir * { box-sizing: border-box; }
        .ir-top { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 14px; }
        .ir-top input, .ir-top select { height: 38px; border: 1px solid var(--ir-line); border-radius: 10px; padding: 0 12px;
              background: var(--ir-bg); color: var(--ir-text); font-size: 14px; }
        .ir-top input[type=text] { min-width: 220px; }
        .ir-top input[type=number] { width: 96px; }
        .ir-prog { margin-left: auto; display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--ir-muted); }
        .ir-bar { width: 160px; height: 8px; border-radius: 99px; background: var(--ir-wash); overflow: hidden; }
        .ir-bar i { display: block; height: 100%; background: var(--ir-green); }
        .ir-grid { display: grid; grid-template-columns: minmax(340px, 420px) 1fr; gap: 16px; align-items: start; }
        @media (max-width: 1000px) { .ir-grid { grid-template-columns: 1fr; } }
        .ir-list { border: 1px solid var(--ir-line); border-radius: 14px; background: var(--ir-bg); overflow: hidden; }
        .ir-row { display: grid; grid-template-columns: 52px 1fr auto; gap: 12px; align-items: center; padding: 8px 12px;
              border-bottom: 1px solid var(--ir-line); cursor: pointer; text-align: left; width: 100%; background: none; color: inherit; }
        .ir-row:last-child { border-bottom: 0; }
        .ir-row:hover { background: var(--ir-wash); }
        .ir-row.on { background: var(--ir-green-soft); box-shadow: inset 3px 0 0 var(--ir-green); }
        .ir-ic { width: 52px; height: 52px; border-radius: 12px; background: var(--ir-wash); display: grid; place-items: center; overflow: hidden; }
        .ir-ic img { width: 46px; height: 46px; object-fit: contain; }
        .ir-ic span { font-size: 20px; color: var(--ir-muted); font-weight: 700; }
        .ir-row b { font-size: 15px; display: block; }
        .ir-row small { color: var(--ir-muted); font-size: 12.5px; display: block; margin-top: 1px; }
        .ir-tags { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
        .ir-chip { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 99px; background: var(--ir-wash); color: var(--ir-muted); white-space: nowrap; }
        .ir-chip.ok { background: var(--ir-green-soft); color: var(--ir-green); }
        .ir-chip.mid { background: var(--ir-amber-soft); color: var(--ir-amber); }
        .ir-chip.low { background: var(--ir-red-soft); color: var(--ir-red); }
        .ir-chip.man { background: var(--ir-green); color: #fff; }
        .ir-pager { display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; font-size: 13px; color: var(--ir-muted); background: var(--ir-wash); }
        .ir-pager button { border: 1px solid var(--ir-line); background: var(--ir-bg); color: var(--ir-text); border-radius: 8px; padding: 5px 12px; cursor: pointer; }
        .ir-pager button:disabled { opacity: .4; cursor: default; }
        .ir-panel { border: 1px solid var(--ir-line); border-radius: 14px; background: var(--ir-bg); padding: 18px; position: sticky; top: 80px; }
        .ir-head { display: grid; grid-template-columns: 168px 1fr; gap: 18px; align-items: center; }
        .ir-hero { width: 168px; height: 168px; border-radius: 22px; background: var(--ir-wash); display: grid; place-items: center; overflow: hidden; }
        .ir-hero img { width: 150px; height: 150px; object-fit: contain; filter: drop-shadow(0 12px 18px rgba(0,0,0,.14)); }
        .ir-hero span { color: var(--ir-muted); font-size: 13px; font-weight: 600; text-align: center; padding: 0 14px; }
        .ir-word { font-size: 30px; font-weight: 800; letter-spacing: -.5px; line-height: 1.1; }
        .ir-tr { font-size: 18px; color: var(--ir-green); font-weight: 700; margin-top: 4px; }
        .ir-def { font-size: 13.5px; color: var(--ir-muted); margin-top: 8px; line-height: 1.45; }
        .ir-meta { font-size: 12px; color: var(--ir-muted); margin-top: 6px; }
        .ir-acts { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 16px; }
        .ir-btn { border: 1px solid var(--ir-line); background: var(--ir-bg); color: var(--ir-text); border-radius: 10px; padding: 9px 14px; font-weight: 700; font-size: 13.5px; cursor: pointer; }
        .ir-btn kbd { font: inherit; font-size: 11px; opacity: .55; margin-left: 6px; }
        .ir-btn.ok { background: var(--ir-green); border-color: var(--ir-green); color: #fff; }
        .ir-btn.no { background: var(--ir-red-soft); border-color: transparent; color: var(--ir-red); }
        .ir-btn:disabled { opacity: .4; cursor: default; }
        .ir-sub { display: flex; align-items: center; gap: 10px; margin: 20px 0 10px; }
        .ir-sub h3 { font-size: 13px; font-weight: 800; letter-spacing: .6px; text-transform: uppercase; color: var(--ir-muted); margin: 0; }
        .ir-sub input { margin-left: auto; height: 36px; border: 1px solid var(--ir-line); border-radius: 10px; padding: 0 12px; min-width: 240px; background: var(--ir-bg); color: var(--ir-text); font-size: 13.5px; }
        .ir-cands { display: grid; grid-template-columns: repeat(auto-fill, minmax(118px, 1fr)); gap: 10px; }
        .ir-cand { border: 1px solid var(--ir-line); border-radius: 14px; padding: 10px 8px 8px; background: var(--ir-bg); cursor: pointer; text-align: center; position: relative; color: inherit; }
        .ir-cand:hover { border-color: var(--ir-green); background: var(--ir-green-soft); }
        .ir-cand.cur { border: 2px solid var(--ir-green); }
        .ir-cand img { width: 84px; height: 84px; object-fit: contain; display: block; margin: 0 auto 6px; }
        .ir-cand b { display: block; font-size: 12px; line-height: 1.25; }
        .ir-cand small { display: block; font-size: 10.5px; color: var(--ir-muted); margin-top: 2px; }
        .ir-cand .n { position: absolute; top: 6px; left: 8px; font-size: 10.5px; font-weight: 800; color: var(--ir-muted); }
        .ir-empty { padding: 40px 20px; text-align: center; color: var(--ir-muted); font-size: 14px; }
        .ir-keys { margin-top: 14px; font-size: 12px; color: var(--ir-muted); }
        .ir-keys kbd { border: 1px solid var(--ir-line); border-radius: 5px; padding: 0 5px; font-size: 11px; }
    </style>

    <div
        class="ir"
        x-data
        x-on:keydown.window="
            if (['INPUT','SELECT','TEXTAREA'].includes(document.activeElement.tagName)) return;
            if ($event.key === 'ArrowDown' || $event.key === 'j') { $event.preventDefault(); $wire.move(1) }
            else if ($event.key === 'ArrowUp' || $event.key === 'k') { $event.preventDefault(); $wire.move(-1) }
            else if ($event.key === 'Enter') { $event.preventDefault(); $wire.approve() }
            else if ($event.key === 'Backspace' || $event.key === 'Delete' || $event.key === 'x') { $event.preventDefault(); $wire.reject() }
            else if (/^[1-9]$/.test($event.key)) { $wire.pickIndex(parseInt($event.key) - 1) }
            else if ($event.key === '/') { $event.preventDefault(); document.getElementById('ir-icon-q')?.focus() }
        "
    >
        <div class="ir-top">
            <input type="text" placeholder="Soʼzni qidiring…" wire:model.live.debounce.300ms="search" />
            <select wire:model.live="status">
                <option value="pending">Koʼrilmaganlar</option>
                <option value="low">Ishonchi past (&lt; 80)</option>
                <option value="none">Ikonkasiz</option>
                <option value="manual">Tasdiqlanganlar</option>
                <option value="all">Hammasi</option>
            </select>
            <label style="font-size:13px;color:var(--ir-muted)">Reyting ≤ <input type="number" min="1" step="500" wire:model.live.debounce.500ms="maxRank" /></label>
            <div class="ir-prog">
                <span><b>{{ $progress['manual'] }}</b> / {{ $progress['total'] }} tasdiqlangan · {{ $progress['with_icon'] }} ikonkali</span>
                <span class="ir-bar"><i style="width: {{ $progress['percent'] }}%"></i></span>
                <span>{{ $progress['percent'] }}%</span>
            </div>
        </div>

        <div class="ir-grid">
            <div class="ir-list">
                @forelse ($words as $word)
                    <button type="button" class="ir-row {{ $selected?->id === $word->id ? 'on' : '' }}" wire:click="select({{ $word->id }})" wire:key="w-{{ $word->id }}">
                        <span class="ir-ic">
                            @if ($word->icon_url)
                                <img src="{{ $word->icon_url }}" alt="" loading="lazy" />
                            @else
                                <span>{{ $word->emoji ?: mb_substr($word->word, 0, 1) }}</span>
                            @endif
                        </span>
                        <span>
                            <b>{{ $word->word }}</b>
                            <small>{{ $word->translation('uz') ?? '—' }} · {{ $word->part_of_speech ?? '?' }} · #{{ $word->frequency_rank }}</small>
                        </span>
                        <span class="ir-tags">
                            @if ($word->icon_source === 'manual')
                                <span class="ir-chip man">✓ admin</span>
                            @elseif ($word->icon_id)
                                <span class="ir-chip {{ $chip($word->icon_confidence) }}">{{ $word->icon_confidence }}%</span>
                            @else
                                <span class="ir-chip">yoʼq</span>
                            @endif
                        </span>
                    </button>
                @empty
                    <div class="ir-empty">Bu filtrga mos soʼz qolmadi 🎉</div>
                @endforelse

                <div class="ir-pager">
                    <button type="button" wire:click="previousPage" @disabled($words->onFirstPage())>← Oldingi</button>
                    <span>{{ $words->firstItem() ?? 0 }}–{{ $words->lastItem() ?? 0 }} / {{ $words->total() }}</span>
                    <button type="button" wire:click="nextPage" @disabled(! $words->hasMorePages())>Keyingi →</button>
                </div>
            </div>

            <div class="ir-panel">
                @if ($selected)
                    <div class="ir-head">
                        <div class="ir-hero">
                            @if ($selected->icon_large_url)
                                <img src="{{ $selected->icon_large_url }}" alt="" />
                            @else
                                <span>Ikonka yoʼq{{ $selected->emoji ? ' · '.$selected->emoji : '' }}</span>
                            @endif
                        </div>
                        <div>
                            <div class="ir-word">{{ $selected->word }}</div>
                            <div class="ir-tr">{{ implode(', ', $selected->acceptedAnswers('uz')) ?: '—' }}</div>
                            @if ($def = $selected->definition['en'] ?? null)
                                <div class="ir-def">{{ \Illuminate\Support\Str::limit($def, 180) }}</div>
                            @endif
                            <div class="ir-meta">
                                {{ $selected->part_of_speech ?? '?' }} · #{{ $selected->frequency_rank }} · {{ $selected->cefr_level ?? '—' }}
                                @if ($selected->icon)
                                    · hozirgi: <b>{{ $selected->icon->title }}</b>
                                    ({{ $selected->icon_source === 'manual' ? 'admin' : ($selected->icon_source ?? 'auto') }}{{ $selected->icon_confidence ? ', '.$selected->icon_confidence.'%' : '' }})
                                @endif
                            </div>
                            <div class="ir-acts">
                                <button type="button" class="ir-btn ok" wire:click="approve" @disabled(! $selected->icon_id)>Toʼgʼri <kbd>Enter</kbd></button>
                                <button type="button" class="ir-btn no" wire:click="reject">Rasm yoʼq <kbd>⌫</kbd></button>
                                @if ($selected->icon_source === 'manual')
                                    <button type="button" class="ir-btn" wire:click="release">Qarorni bekor qil</button>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="ir-sub">
                        <h3>{{ trim($iconQuery) !== '' ? 'Kutubxonadan qidiruv' : 'Takliflar' }}</h3>
                        <input id="ir-icon-q" type="text" placeholder="Kutubxonadan qidirish (/)…" wire:model.live.debounce.300ms="iconQuery" />
                    </div>

                    <div class="ir-cands">
                        @forelse ($candidates as $i => $icon)
                            <button type="button" class="ir-cand {{ $selected->icon_id === $icon->id ? 'cur' : '' }}" wire:click="pick('{{ $icon->slug }}')" wire:key="c-{{ $icon->slug }}">
                                @if ($i < 9 && trim($iconQuery) === '')<span class="n">{{ $i + 1 }}</span>@endif
                                <img src="{{ $icon->url(256) }}" alt="" loading="lazy" />
                                <b>{{ $icon->title }}</b>
                                <small>{{ $icon->category }}</small>
                            </button>
                        @empty
                            <div class="ir-empty">Taklif topilmadi — kutubxonadan qidiring.</div>
                        @endforelse
                    </div>

                    <div class="ir-keys">
                        <kbd>↑</kbd><kbd>↓</kbd> soʼz · <kbd>Enter</kbd> toʼgʼri · <kbd>⌫</kbd> rasm yoʼq · <kbd>1</kbd>–<kbd>9</kbd> taklifni tanlash · <kbd>/</kbd> qidiruv
                    </div>
                @else
                    <div class="ir-empty">Chapdan soʼzni tanlang. Qaror qilingan soʼz roʼyxatdan chiqadi va keyingisi avtomatik tanlanadi.</div>
                @endif
            </div>
        </div>
    </div>
</x-filament-panels::page>
