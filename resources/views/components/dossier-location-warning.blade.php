@props(['dossier'])
@if (\App\Services\Crm\DossierLocation::needsReview($dossier))
    <span {{ $attributes->class('mt-1 inline-flex items-center gap-1 rounded-md bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-900') }} title="L’adresse a été saisie manuellement. Aucune coordonnée GPS n’a pu être confirmée."><x-nav-icon name="location" />Localisation à vérifier</span>
@endif
