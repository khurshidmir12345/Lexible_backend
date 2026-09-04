@php $record = $getRecord(); @endphp
<div style="width:44px;height:44px;border-radius:12px;background:rgba(127,127,127,.10);display:grid;place-items:center;overflow:hidden">
    @if ($record->icon_url)
        <img src="{{ $record->icon_url }}" alt="" loading="lazy" style="width:40px;height:40px;object-fit:contain" />
    @else
        <span style="font-size:18px;font-weight:700;opacity:.6">{{ $record->emoji ?: mb_substr($record->word, 0, 1) }}</span>
    @endif
</div>
