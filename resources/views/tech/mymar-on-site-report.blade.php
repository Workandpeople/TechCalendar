<x-layouts.app>
    <div class="tech-workspace space-y-4">
        <header class="tech-panel"><p class="text-sm font-semibold text-amber-800">MAR · Visite après travaux</p><h1 class="mt-1 text-xl font-semibold">{{ $source['customer'] }}</h1><p class="mt-2 break-words">{{ $source['address'] }}</p><p class="mt-2 text-sm">RDV du {{ \Carbon\CarbonImmutable::parse($source['starts_at'])->tz('Europe/Paris')->format('d/m/Y à H:i') }}</p></header>
        @if ($incident)
            <section class="tech-panel space-y-4">
                <h2 class="text-lg font-semibold">Signalement enregistré le {{ $incident->reported_at->format('d/m/Y à H:i') }}</h2>
                @include('tech.partials.mymar-report-state')
                <p class="font-semibold">{{ $incident->reasonLabel() }}</p>
                <p class="whitespace-pre-line break-words">{{ $incident->comment }}</p>
                <a class="gc-btn-secondary" href="{{ route('tech.mar.on-site.photo', $incident) }}">Voir la photo</a>
                @if ($incident->sourceRequest?->status === 'queued')
                    <form method="POST" action="{{ route('tech.mar.on-site.transmit', $rdv) }}">@csrf<button class="gc-btn-primary w-full" type="submit">Transmettre le signalement à MYMAR</button></form>
                @elseif (in_array($incident->sourceRequest?->status, ['uncertain', 'dispatching'], true))
                    <a class="gc-btn-secondary" href="{{ route('tech.mar.on-site.show', $rdv) }}">Vérifier la confirmation</a>
                @endif
            </section>
        @else
            <details id="tech-on-site-report" class="tech-panel tech-on-site-report" open>
                <summary><x-nav-icon name="alert" /><span>Signaler un problème sur place<small>Client absent, refus du RDV ou accès impossible</small></span></summary>
                <form method="POST" action="{{ route('tech.mar.on-site.store', $rdv) }}" enctype="multipart/form-data" data-on-site-report data-actor="{{ auth()->id() }}" data-appointment="mar-{{ $rdv }}">
                    @csrf
                    <input type="hidden" name="request_id" value="{{ old('request_id', (string) \Illuminate\Support\Str::uuid()) }}">
                    <input type="hidden" name="snapshot" value="{{ $snapshot }}">
                    <p class="tech-report-intro">La validation alerte le planning Cofrac et demande le passage en <strong>PB prise de RDV APT dans MYMAR</strong>, sans supprimer le rendez-vous ni son historique.</p>
                    <p class="tech-report-help">Photo et commentaire complet conservés dans le suivi interne Cofrac. MYMAR reçoit l’état et un motif court tracé. Aucun email ou SMS automatique.</p>
                    @if ($errors->any())<ul class="tech-report-errors" role="alert">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
                    <label for="mar-reason">Motif *</label>
                    <select id="mar-reason" name="reason" class="gc-input" required><option value="">Choisir un motif</option>@foreach (\App\Services\Tech\TechnicianOnSiteReports::REASONS as $key => $label)<option value="{{ $key }}" @selected(old('reason') === $key)>{{ $label }}</option>@endforeach</select>
                    <label for="mar-photo" class="tech-report-photo-label"><x-nav-icon name="document" /><span>Prendre une photo sur place<small>Obligatoire · JPEG, PNG ou WebP · 10 Mo maximum</small></span></label>
                    <input id="mar-photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp" capture="environment" required>
                    <p class="tech-report-help">Évitez les visages et documents personnels. Les métadonnées GPS sont retirées de la photo.</p>
                    <figure data-report-preview hidden><img alt="Photo du signalement à envoyer"><figcaption data-report-photo-name></figcaption></figure>
                    <label for="mar-comment">Commentaire (obligatoire pour « Autre »)</label>
                    <textarea id="mar-comment" name="comment" rows="3" maxlength="2000" class="gc-input">{{ old('comment') }}</textarea>
                    <p class="tech-report-draft" data-report-draft-status role="status">Le brouillon reste sur cet appareil pendant 24 h. Rien n’est envoyé sans validation.</p>
                    <div class="tech-report-feedback" data-report-feedback role="alert" hidden></div>
                    <div class="tech-report-actions"><button class="gc-btn-danger" type="submit" data-report-submit>Valider le signalement</button><button class="gc-btn-secondary" type="button" data-report-discard>Effacer le brouillon</button></div>
                </form>
            </details>
        @endif
        <a class="gc-btn-secondary" href="{{ route('tech.planning') }}">Retour à mon planning</a>
    </div>
</x-layouts.app>
