<x-mail::message>
@if (! empty($logoUrl))
<div style="text-align:center;margin-bottom:24px;">
<img src="{{ $logoUrl }}" alt="{{ config('app.name') }}" style="display:inline-block;max-width:180px;max-height:90px;height:auto;width:auto;">
</div>
@endif

{{ $markdown }}
</x-mail::message>
