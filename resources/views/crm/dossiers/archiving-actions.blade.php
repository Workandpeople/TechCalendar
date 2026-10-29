@can('manageArchiving', \App\Models\Dossier::class)
    <div class="space-y-2" data-dossier-archiving-feedback aria-busy="false">
        <p data-dossier-archiving-status role="status" aria-live="polite" class="text-sm text-slate-600"></p>
        <div data-dossier-archiving-error hidden class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">
            <p data-dossier-archiving-message role="alert"></p>
            <button type="button" data-dossier-archiving-refresh class="mt-2 font-semibold underline">Actualiser le tableau</button>
        </div>
    </div>
@endcan
