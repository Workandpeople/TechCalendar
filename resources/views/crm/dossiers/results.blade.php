@include('crm.dossiers.table')
<div class="flex flex-wrap items-center justify-between gap-3">
    <label class="flex items-center gap-2 text-sm">Lignes par page
        <select class="gc-input w-auto" data-dossier-page-size aria-label="Nombre de dossiers par page">
            @foreach ([25, 50, 100] as $size)<option value="{{ $size }}" @selected(($filters['per_page'] ?? 25) == $size)>{{ $size }}</option>@endforeach
        </select>
    </label>
    {{ $dossiers->links() }}
</div>
