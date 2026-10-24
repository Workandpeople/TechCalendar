@props(['conflict' => null])

@if ($conflict)
    <div class="gc-alert" role="status">
        <p>{{ $conflict['message'] }}</p>
        @if ($conflict['url'])
            <a href="{{ $conflict['url'] }}" class="gc-link">{{ $conflict['label'] }}</a>
        @endif
    </div>
@endif
