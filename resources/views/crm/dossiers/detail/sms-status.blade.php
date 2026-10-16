<ul class="space-y-3">
    @forelse ($messages as $sms)
        <li class="rounded-lg border border-slate-200 p-3 text-sm" @if ($sms->status === 'queued' || ($sms->status === 'sending' && $sms->started_at?->gt(now()->subMinutes(2)))) data-sms-active @endif>
            <div class="flex flex-wrap justify-between gap-2"><span class="font-semibold">#{{ $sms->id }} · {{ $sms->mode === 'simulate' ? 'Simulation' : 'Envoi réel' }} · {{ $sms->recipient }}</span><time class="text-xs text-slate-500">{{ $sms->created_at->format('d/m/Y H:i') }}</time></div>
            <p @class(['mt-2 font-medium', 'text-emerald-700' => in_array($sms->status, ['accepted', 'simulated']), 'text-rose-700' => in_array($sms->status, ['unknown', 'blocked', 'rejected'])])>{{ $sms->statusLabel() }}</p>
            @if ($sms->last_error)<p class="mt-1 text-xs text-slate-600">{{ $sms->last_error }}</p>@endif
            @if ($sms->provider_ticket)<p class="mt-1 text-xs text-slate-500">Référence SMSFactor : {{ $sms->provider_ticket }}</p>@endif
            <details class="mt-2"><summary class="cursor-pointer text-xs underline">Voir le texte confirmé</summary><p class="mt-2 whitespace-pre-wrap break-words">{{ $sms->body }}</p></details>
            @if ($sms->status === 'queued')<form method="POST" action="{{ route('v2.dossiers.sms.resume', [$dossier, $sms]) }}" class="mt-2">@csrf<button class="gc-btn-soft" type="submit">Reprendre la mise en file</button></form>@endif
        </li>
    @empty
        <li class="text-sm text-slate-500">Aucune demande SMS depuis TechCalendar.</li>
    @endforelse
</ul>
