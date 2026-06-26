<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ApplicationSettings;
use App\Services\ExternalAppointmentSourceRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminSettingController extends Controller
{
    public function index(
        Request $request,
        ApplicationSettings $settings,
        ExternalAppointmentSourceRegistry $externalSources
    ): View
    {
        abort_unless((bool) $request->user()?->admin, 403);

        return view('admin.settings', [
            'groups' => collect($settings->formRows())->groupBy('group'),
            'externalAppointmentSources' => $externalSources->resetRows(),
        ]);
    }

    public function update(Request $request, ApplicationSettings $settings): RedirectResponse
    {
        abort_unless((bool) $request->user()?->admin, 403);

        $definitions = $settings->definitions();
        $payload = collect($definitions)
            ->mapWithKeys(fn (array $definition, string $key): array => [
                $key => $request->input("settings.{$key}"),
            ])
            ->all();

        $rules = collect($definitions)
            ->mapWithKeys(fn (array $definition, string $key): array => [
                $key => $definition['rules'] ?? ['nullable', 'string'],
            ])
            ->all();

        $values = Validator::make($payload, $rules)->validate();
        $settings->update($values, $request->user()->id);

        return redirect()->route('admin.settings')->with('status', 'Paramètres mis à jour.');
    }

    public function destroy(Request $request, ApplicationSettings $settings): RedirectResponse
    {
        abort_unless((bool) $request->user()?->admin, 403);
        $key = (string) $request->input('key');
        abort_unless(array_key_exists($key, $settings->definitions()), 404);

        $settings->forget($key, $request->user()->id);

        return redirect()->route('admin.settings')->with('status', 'Paramètre repassé sur la valeur .env.');
    }

    public function resetExternalAppointments(
        Request $request,
        ExternalAppointmentSourceRegistry $externalSources
    ): RedirectResponse
    {
        abort_unless((bool) $request->user()?->admin, 403);

        $payload = $request->validate([
            'source' => ['required', 'string', Rule::in($externalSources->keys())],
        ]);

        $result = $externalSources->resetLocalCache((string) $payload['source']);
        $label = $externalSources->label((string) $payload['source']);

        return redirect()
            ->route('admin.settings')
            ->with(
                'status',
                sprintf(
                    '%s réinitialisé: %d RDV API local(aux) supprimé(s), %d état(s) de synchronisation supprimé(s). Les RDV TechCalendar déjà créés sont conservés.',
                    $label,
                    $result['deleted_appointments'],
                    $result['deleted_sync_states'],
                )
            );
    }
}
