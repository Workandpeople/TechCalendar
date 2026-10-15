<x-layouts.app :crm-navigation="true">
    <div class="mx-auto max-w-5xl space-y-6">
        <header>
            <a class="text-sm underline" href="{{ route('v2.dossiers.show', $dossier) }}#capcee">Retour au dossier</a>
            <h1 class="mt-3 text-2xl font-semibold">{{ $report ? 'Nouvelle version du rapport CAPCEE' : 'Transmettre un rapport à CAPCEE' }}</h1>
            <p class="mt-2 text-sm text-slate-600">{{ $dossier->beneficiaryLabel() }} · {{ $operation->service?->name ?: $operation->legacy_service_name }} · {{ config('capcee.environment') === 'production' ? 'PRODUCTION' : 'Test UAT2' }}</p>
        </header>
        @include('crm.dossiers.notice')
        @if ($capceeUnavailable)<p role="alert" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm">{{ $capceeUnavailable }}</p>@endif
        @php($previous = $report?->latestDelivery?->metadata ?? [])
        <form method="POST" enctype="multipart/form-data" action="{{ $report ? route('v2.capcee.versions.store', [$dossier, $operation, $report]) : route('v2.capcee.store', [$dossier, $operation]) }}" class="gc-card space-y-6 p-6">
            @csrf
            <input type="hidden" name="submission_token" value="{{ old('submission_token', (string) \Illuminate\Support\Str::uuid()) }}">
            @if ($report)<p class="break-all text-sm font-medium">Référence stable : GC_{{ $report->reference }}. La nouvelle version remplacera la version courante du même document, sans créer un second rapport.</p>@endif
            <fieldset class="grid gap-4 md:grid-cols-2">
                <legend class="mb-3 font-semibold">Cible et contrôle</legend>
                <div class="md:col-span-2"><label for="capcee-emmy" class="gc-label">Référence client / EMMY</label><input id="capcee-emmy" name="emmy_reference" class="gc-input" value="{{ old('emmy_reference', $report?->emmy_reference) }}" maxlength="255" required @readonly($report)><p class="mt-1 text-xs text-slate-500">La référence exacte de l’opération CAPCEE, pas le nom du lot ni la référence du dossier TechCalendar.</p></div>
                <div><label for="capcee-object" class="gc-label">Cible CAPCEE</label><select id="capcee-object" name="object_type" class="gc-input" required>
                    @foreach (['Operation__c' => 'Opération', 'Action__c' => 'Action (mission groupée)'] as $value => $label)
                        @if (! $report || $report->object_type === $value)<option value="{{ $value }}" @selected(old('object_type', $report?->object_type ?? 'Operation__c') === $value)>{{ $label }}</option>@endif
                    @endforeach
                </select></div>
                <div><label for="capcee-type" class="gc-label">Type d’inspection</label><select id="capcee-type" name="inspection_type" class="gc-input" required>
                    @foreach (['site' => 'Sur site', 'contact' => 'Par contact'] as $value => $label)
                        @if (! $report || $report->inspection_type === $value)<option value="{{ $value }}" @selected(old('inspection_type', $report?->inspection_type ?? 'site') === $value)>{{ $label }}</option>@endif
                    @endforeach
                </select></div>
                <div><label for="capcee-inspected" class="gc-label">Date d’inspection réalisée</label><input id="capcee-inspected" type="date" name="inspected_on" class="gc-input" value="{{ old('inspected_on', $previous['inspected_on'] ?? '') }}" required></div>
                <div><label for="capcee-issued" class="gc-label">Date d’émission / signature du rapport</label><input id="capcee-issued" type="date" name="issued_on" class="gc-input" value="{{ old('issued_on') }}" required></div>
                <div><label for="capcee-conclusion" class="gc-label">Conclusion du rapport</label><select id="capcee-conclusion" name="conclusion" class="gc-input" required><option value="">Choisir la conclusion</option>
                    @foreach (['Satisfaisant' => 'Satisfaisant', 'NonSatisfaisant' => 'Non satisfaisant', 'NonVerifiable' => 'Non vérifiable', 'Autre' => 'Autre'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('conclusion', $previous['conclusion'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select></div>
                <div><label for="capcee-email" class="gc-label">Email au bénéficiaire via CAPCEE</label><select id="capcee-email" name="send_email" class="gc-input" required><option value="0" @selected((string) old('send_email', '0') === '0')>Ne pas envoyer</option><option value="1" @selected((string) old('send_email', '0') === '1')>Autoriser l’envoi</option></select></div>
                @if ($report)<div class="md:col-span-2"><label for="capcee-reason" class="gc-label">Motif de cette nouvelle version</label><input id="capcee-reason" name="reason_for_change" maxlength="255" value="{{ old('reason_for_change') }}" class="gc-input" required></div>@endif
                <div class="md:col-span-2"><label for="capcee-pdf" class="gc-label">Rapport PDF validé (35 Mo maximum)</label><input id="capcee-pdf" name="report_file" type="file" accept=".pdf,application/pdf" class="block w-full min-w-0 text-sm" required><p class="mt-2 text-xs text-slate-500">Une copie privée immuable et son empreinte seront conservées. Le worker préparera l’envoi, hors de cette requête.</p></div>
                <label class="flex items-start gap-3 text-sm md:col-span-2"><input name="validated_report" type="checkbox" value="1" required class="mt-1"><span>Je confirme que ce rapport est validé, que la référence EMMY est correcte et que sa transmission à CAPCEE est autorisée.</span></label>
            </fieldset>
            <footer class="flex flex-wrap justify-end gap-3 border-t border-slate-200 pt-5"><a class="gc-btn-secondary" href="{{ route('v2.dossiers.show', $dossier) }}#capcee">Annuler</a><button class="gc-btn-primary" type="submit" @disabled($capceeUnavailable)>Valider et transmettre</button></footer>
        </form>
    </div>
</x-layouts.app>
