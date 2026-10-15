@props(['context'])
<details class="gc-workspace-help" data-workspace-help>
    <summary aria-label="Mode d’emploi"><x-nav-icon name="help" /><span class="hidden sm:inline">Mode d’emploi</span></summary>
    <div class="gc-workspace-help-panel">
        <p class="gc-workspace-eyebrow">{{ $context['label'] }}</p>
        <h2>Comment faire ?</h2>
        <ol>
            @foreach ($context['steps'] as [$label, $description])
                <li><span class="gc-workspace-step" aria-hidden="true">{{ $loop->iteration }}</span><div><strong>{{ $label }}</strong><p>{{ $description }}</p></div></li>
            @endforeach
        </ol>
        <p class="gc-workspace-help-hint">Les actions proposées dépendent de vos droits et de l’état du dossier.</p>
    </div>
</details>
