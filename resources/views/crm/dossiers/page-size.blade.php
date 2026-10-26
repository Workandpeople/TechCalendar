<label class="gc-pagination-page-size">
    Dossiers par page
    <select class="gc-input" data-dossier-page-size aria-label="Nombre de dossiers par page">
        @foreach ([25, 50, 100] as $size)<option value="{{ $size }}" @selected($paginator->perPage() == $size)>{{ $size }}</option>@endforeach
    </select>
</label>
