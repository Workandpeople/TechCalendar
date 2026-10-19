@props(['label' => 'Déjà facturé', 'tone' => 'green'])
@php($colors = match ($tone) {
    'green' => 'border-emerald-300 bg-emerald-100 text-emerald-900',
    'amber' => 'border-amber-300 bg-amber-100 text-amber-900',
    default => 'border-slate-200 bg-slate-100 text-slate-700',
})
<span {{ $attributes->class(['inline-flex items-center gap-1 rounded-md border px-2 py-1 text-xs font-semibold', $colors]) }}><x-nav-icon name="check" />{{ $label }}</span>
