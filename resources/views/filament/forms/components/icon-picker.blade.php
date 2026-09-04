@php
    use App\Models\Icon;
    use App\Models\IconCandidate;

    $state = $getState();
    $current = $state ? Icon::find($state) : null;
    $record = $getRecord();
    $word = mb_strtolower(trim((string) ($record?->normalized ?? $getLivewire()->data['word'] ?? '')));
    $slugs = $word !== '' ? IconCandidate::slugsFor($word) : [];
    $bySlug = $slugs ? Icon::query()->whereIn('slug', $slugs)->get()->keyBy('slug') : collect();
    $suggestions = collect($slugs)->map(fn ($s) => $bySlug->get($s))->filter()->values();
    $path = $getStatePath();
@endphp

<style>
    .ip { display: grid; grid-template-columns: 200px 1fr; gap: 20px; align-items: start; }
    @media (max-width: 800px) { .ip { grid-template-columns: 1fr; } }
    .ip-cur { width: 200px; height: 200px; border-radius: 24px; background: rgba(127,127,127,.10); display: grid; place-items: center; overflow: hidden; }
    .ip-cur img { width: 176px; height: 176px; object-fit: contain; filter: drop-shadow(0 12px 18px rgba(0,0,0,.14)); }
    .ip-cur span { opacity: .6; font-size: 13px; font-weight: 600; text-align: center; padding: 0 16px; }
    .ip-cap { margin-top: 8px; font-size: 13px; font-weight: 700; text-align: center; }
    .ip-x { display: block; margin: 6px auto 0; font-size: 12px; color: #b91c1c; background: none; border: 0; cursor: pointer; font-weight: 700; }
    .ip-h { font-size: 11.5px; font-weight: 800; letter-spacing: .6px; text-transform: uppercase; opacity: .6; margin: 0 0 8px; }
    .ip-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(104px, 1fr)); gap: 8px; }
    .ip-c { border: 1px solid rgba(127,127,127,.25); border-radius: 12px; padding: 8px 6px 6px; background: none; cursor: pointer; text-align: center; color: inherit; }
    .ip-c:hover { border-color: #17a45c; background: rgba(23,164,92,.08); }
    .ip-c.on { border: 2px solid #17a45c; }
    .ip-c img { width: 72px; height: 72px; object-fit: contain; display: block; margin: 0 auto 4px; }
    .ip-c b { display: block; font-size: 11.5px; line-height: 1.2; }
    .ip-note { font-size: 12.5px; opacity: .65; margin-top: 8px; }
</style>

<div class="ip" wire:key="ip-{{ $state ?? 'none' }}-{{ $word }}">
    <div>
        <div class="ip-cur">
            @if ($current)
                <img src="{{ $current->url(512) }}" alt="" />
            @else
                <span>Ikonka tanlanmagan</span>
            @endif
        </div>
        @if ($current)
            <div class="ip-cap">{{ $current->title }}</div>
            <button type="button" class="ip-x" wire:click="$set('{{ $path }}', null)">✕ Ikonkani olib tashlash</button>
        @endif
    </div>
    <div>
        <p class="ip-h">Takliflar{{ $word !== '' ? " — «{$word}»" : '' }}</p>
        @if ($suggestions->isEmpty())
            <div class="ip-note">Bu soʼz uchun avtomatik taklif yoʼq — yuqoridagi qidiruvdan kutubxonaning 10 000 ikonkasi ichidan tanlang.</div>
        @else
            <div class="ip-grid">
                @foreach ($suggestions as $icon)
                    <button type="button" class="ip-c {{ $state === $icon->id ? 'on' : '' }}" wire:click="$set('{{ $path }}', {{ $icon->id }})" wire:key="ipc-{{ $icon->id }}">
                        <img src="{{ $icon->url(256) }}" alt="" loading="lazy" />
                        <b>{{ $icon->title }}</b>
                    </button>
                @endforeach
            </div>
            <div class="ip-note">Bosilgan ikonka darhol tanlanadi; saqlaganingizda soʼzga biriktiriladi va avtomatika uni boshqa oʼzgartirmaydi.</div>
        @endif
    </div>
</div>
