@props(['id', 'title', 'reopen' => false])
<dialog id="{{ $id }}" class="gc-dossier-dialog" data-dossier-dialog @if ($reopen) data-dialog-reopen @endif aria-labelledby="{{ $id }}-title">
    <header><h2 id="{{ $id }}-title" tabindex="-1">{{ $title }}</h2><button type="button" class="gc-btn-secondary" data-dialog-close aria-label="Fermer : {{ $title }}">Fermer</button></header>
    <div class="gc-dossier-dialog-body">
        @if ($reopen && $errors->any())<div role="alert" class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-800"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        {{ $slot }}
    </div>
</dialog>
