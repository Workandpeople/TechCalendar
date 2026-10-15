@props(['status' => null])
@php($description = \App\Services\Crm\DossierStatusCatalog::describe($status))
<span {{ $attributes->class(['gc-dossier-status']) }} data-tone="{{ $description['tone'] }}">{{ $description['label'] }}</span>
