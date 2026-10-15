<details class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-xs text-slate-600">
    <summary class="cursor-pointer font-semibold text-slate-600">V2 en préparation. Fonctionnalités disponibles et limites</summary>
    <p class="mt-3 leading-relaxed">Les dossiers, rendez-vous et décisions après visite partagent le registre natif. Les questionnaires terrain, la génération des rapports et la reprise complète des archives restent à finaliser. Les documents collectés sont accessibles en privé. Le rattachement ne déclenche aucun envoi CRM.</p>
</details>
@if ($errors->any())
    <div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
        <p class="font-semibold">L’enregistrement n’a pas été effectué.</p>
        <ul class="mt-2 list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif
@if (session('status'))
    <p role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('status') }}</p>
@endif
