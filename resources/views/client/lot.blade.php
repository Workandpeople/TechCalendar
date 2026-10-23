<x-layouts.app>
    @php
        $categories = collect(['physical' => 'RDV physiques', 'contact' => 'Contacts'])
            ->filter(fn ($label, $key) => $stats[$key] !== null);
    @endphp
    <div class="space-y-6">
        <a class="gc-link" href="{{ route('client.lots') }}">← Mes lots</a>
        <x-workspace-heading :title="$lot->name" :eyebrow="\App\Models\Lot::statusLabelFor($lot->status)" :description="'Réception : '.($lot->received_at?->format('d/m/Y') ?: 'non renseignée')" />
        <section class="grid gap-4 sm:grid-cols-3">
            <div class="gc-card p-5">
                <p class="text-sm text-slate-500">Dossiers du lot</p>
                <p class="mt-2 text-2xl font-semibold">{{ $rows->total() }}</p>
            </div>
            @foreach ($categories as $key => $label)
                <div class="gc-card p-5">
                    <p class="text-sm text-slate-500">{{ $label }}</p>
                    <p class="mt-2 text-2xl font-semibold">{{ $stats[$key]['completed_count'] }} / {{ $stats[$key]['target_count'] }}</p>
                    <p class="mt-2 text-sm">{{ $stats[$key]['raw_satisfied_count'] }} satisfaisant(s) · {{ $stats[$key]['raw_unsatisfied_count'] }} non satisfaisant(s)</p>
                </div>
            @endforeach
        </section>
        @if ($restricted)<p class="text-sm text-slate-500">Seuls les dossiers autorisés sont affichés. Les résultats globaux du lot sont masqués lorsque des catégories sont restreintes.</p>@endif
        <div class="overflow-x-auto rounded-xl border border-slate-200">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50">
                    <tr>
                        @foreach (['Bénéficiaire / site', 'Lieu d’inspection', 'État'] as $label)
                            <th class="p-4">{{ $label }}</th>
                        @endforeach
                        @foreach ($categories as $label)
                            <th class="p-4">Résultat · {{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        <tr>
                            <td class="p-4 font-medium">{{ $row->company_name ?: $row->customer_name }}<p class="text-xs text-slate-500">{{ $row->site_name }}</p></td>
                            <td class="p-4">{{ $row->address }}<p>{{ $row->postal_code }} {{ $row->city }}</p></td>
                            <td class="p-4">{{ \App\Models\LotAppointment::statuses()[$row->status] ?? $row->status }}</td>
                            @foreach ($categories as $key => $label)
                                @php($result = $row->{$key.'_satisfaction'})
                                <td class="p-4">{{ $result === null ? 'Non évalué' : ($result ? 'Satisfaisant' : 'Non satisfaisant') }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ 3 + $categories->count() }}" class="p-6 text-slate-500">Aucun dossier dans ce lot.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $rows->links() }}
    </div>
</x-layouts.app>
