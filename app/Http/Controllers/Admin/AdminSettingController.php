<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ApplicationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class AdminSettingController extends Controller
{
    public function index(Request $request, ApplicationSettings $settings): View
    {
        abort_unless((bool) $request->user()?->admin, 403);

        return view('admin.settings', [
            'groups' => collect($settings->formRows())->groupBy('group'),
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

        return redirect()->route('admin.settings')->with('status', 'Parametres mis a jour.');
    }

    public function destroy(Request $request, ApplicationSettings $settings): RedirectResponse
    {
        abort_unless((bool) $request->user()?->admin, 403);
        $key = (string) $request->input('key');
        abort_unless(array_key_exists($key, $settings->definitions()), 404);

        $settings->forget($key, $request->user()->id);

        return redirect()->route('admin.settings')->with('status', 'Parametre repasse sur la valeur .env.');
    }
}
