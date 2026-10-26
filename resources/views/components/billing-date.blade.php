@props(['date' => null])

<span class="text-xs text-slate-600" data-billing-date>
    @if ($date)
        Facturé le <time datetime="{{ $date->toIso8601String() }}">{{ $date->format('d/m/Y H:i') }}</time>
    @else
        Date de facturation non renseignée
    @endif
</span>
