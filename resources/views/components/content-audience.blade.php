@props(['value' => 'internal'])
<fieldset class="gc-audience-picker">
    <legend class="gc-label mb-2">Qui peut consulter ce contenu ?</legend>
    @foreach (\App\Services\Crm\ContentAudience::LABELS as $key => $title)
        <label data-audience="{{ $key }}"><input type="radio" name="audience" value="{{ $key }}" @checked($value === $key)><span>{{ $title }}</span></label>
    @endforeach
    <p>L’équipe interne conserve l’accès. Les destinataires externes doivent être affectés au dossier et disposer du droit de consultation.</p>
</fieldset>
