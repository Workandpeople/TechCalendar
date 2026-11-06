@if (app(\App\Services\Crm\ClientAccess::class)->allows(auth()->user(), 'documents'))
    <section data-report-exports data-list-url="{{ route('client.report-exports.index') }}" data-lots-url="{{ route('client.report-exports.lots') }}" data-create-url="{{ route('client.report-exports.store') }}" data-initial-lot="{{ $exportLot ?? ($filters['lot'] ?? '') }}">
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3">
            <div><h2 class="text-sm font-semibold">Rapports par lot</h2><p class="mt-1 text-xs text-slate-500">Tous les rapports PDF partagés du lot, réunis dans un ZIP avec leur nom de téléchargement habituel.</p></div>
            <button type="button" class="gc-btn-secondary" data-export-open aria-haspopup="dialog" aria-controls="lot-report-export-dialog"><x-nav-icon name="download" /> Télécharger les rapports d’un lot</button>
        </div>
        <x-dossier-dialog id="lot-report-export-dialog" title="Télécharger les rapports d’un lot">
            <p class="text-sm leading-relaxed text-slate-600">Choisissez un lot précis. L’archive comprend tous ses rapports accessibles, sur toutes les pages et dans tous les états autorisés, indépendamment des filtres de cette liste. Aucun document privé n’est inclus.</p>
            <form class="mt-5 space-y-3" data-export-form>
                <label class="gc-label" for="export-lot-search">Rechercher le numéro de lot</label>
                <input id="export-lot-search" class="gc-input" type="search" maxlength="256" placeholder="Saisissez tout ou partie du numéro" autocomplete="off" data-export-search aria-controls="export-lot-options">
                <div id="export-lot-options" class="max-h-52 space-y-1 overflow-y-auto rounded-lg border border-slate-200 p-2" data-export-options aria-label="Lots accessibles"></div>
                <button type="button" class="gc-link text-sm" data-export-retry hidden>Relancer la recherche</button>
                <p class="text-xs text-slate-500">Seuls les lots de vos dossiers accessibles sont proposés. Affinez la recherche si le lot n’apparaît pas.</p>
                <p class="text-sm font-medium" data-export-selected>Aucun lot sélectionné.</p>
                <button class="gc-btn-primary" type="submit" data-export-submit disabled>Préparer le téléchargement</button>
            </form>
            <p class="mt-4 text-sm" data-export-feedback role="status" aria-live="polite"></p>
            <section class="mt-5 border-t border-slate-200 pt-4" aria-labelledby="export-recent-title">
                <div class="flex items-center justify-between gap-3"><h3 id="export-recent-title" class="text-sm font-semibold">Mes dernières demandes</h3><button class="gc-link text-sm" type="button" data-export-refresh>Actualiser</button></div>
                <div class="mt-3 space-y-3" data-export-recent></div>
            </section>
            <p class="mt-5 text-xs leading-relaxed text-slate-500">La préparation se poursuit même si vous fermez cette fenêtre. Les archives expirent après {{ config('report_exports.lifetime_hours') }} heures et sont ensuite supprimées automatiquement. Les rapports originaux sont conservés.</p>
            <noscript><p class="mt-3 text-sm">Activez JavaScript pour préparer une archive. Les téléchargements individuels restent disponibles dans chaque dossier.</p></noscript>
        </x-dossier-dialog>
    </section>
@endif
