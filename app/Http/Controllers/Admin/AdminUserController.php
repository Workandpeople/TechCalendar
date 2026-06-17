<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\UserCreatedCredentialsMail;
use App\Models\Department;
use App\Models\Service;
use App\Models\User;
use App\Services\UserNameNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminUserController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless((bool) $request->user()?->admin, 403);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'role' => ['nullable', Rule::in(['0', '1', '2'])],
            'admin' => ['nullable', Rule::in(['0', '1'])],
            'status' => ['nullable', Rule::in(['active', 'trashed', 'all'])],
        ]);

        $query = User::query()->with(['services:id', 'departments:code']);
        $status = $validated['status'] ?? 'active';

        if ($status === 'all' || $status === 'trashed') {
            $query->withTrashed();
        }

        if ($status === 'trashed') {
            $query->onlyTrashed();
        }

        if (! empty($validated['q'])) {
            $search = trim($validated['q']);
            $query->where(function ($innerQuery) use ($search): void {
                $innerQuery->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (filled($validated['role'] ?? null)) {
            $query->where('role', (int) $validated['role']);
        }

        if (filled($validated['admin'] ?? null)) {
            $query->where('admin', (bool) $validated['admin']);
        }

        $users = $query->orderByDesc('id')->paginate(15)->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'services' => Service::query()->orderBy('type')->orderBy('name')->get(['id', 'type', 'name']),
            'departments' => Department::query()->orderBy('code')->get(['code', 'name']),
            'filters' => [
                'q' => $validated['q'] ?? '',
                'role' => $validated['role'] ?? '',
                'admin' => $validated['admin'] ?? '',
                'status' => $status,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless((bool) $request->user()?->admin, 403);

        $this->normalizeTechTimeInputs($request);

        $payload = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'role' => ['required', Rule::in([0, 1, 2])],
            'admin' => ['required', 'boolean'],
            'phone' => ['nullable', 'string', 'max:30', 'required_if:role,2'],
            'address' => ['nullable', 'string', 'max:255', 'required_if:role,2'],
            'department_code' => ['nullable', 'string', 'max:3', 'regex:/^(\\d{2,3}|2A|2B)$/i', 'required_if:role,2'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_if:role,2'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_if:role,2'],
            'day_start_time' => ['nullable', 'date_format:H:i', 'required_if:role,2'],
            'day_end_time' => ['nullable', 'date_format:H:i', 'required_if:role,2'],
            'break_duration_minutes' => ['nullable', 'integer', 'min:0', 'max:240', 'required_if:role,2'],
            'service_ids' => ['nullable', 'array', 'required_if:role,2'],
            'service_ids.*' => ['integer', Rule::exists('services', 'id')],
            'department_codes' => ['nullable', 'array', 'required_if:role,2'],
            'department_codes.*' => ['string', Rule::exists('departments', 'code')],
        ]);
        $payload = UserNameNormalizer::normalizePayload($payload);

        $plainPassword = Str::password(10);
        $user = User::query()->create([
            'first_name' => $payload['first_name'],
            'last_name' => $payload['last_name'],
            'email' => $payload['email'],
            'role' => (int) $payload['role'],
            'admin' => (bool) $payload['admin'],
            'password' => Hash::make($plainPassword),
            'must_change_password' => true,
            ...$this->resolveTechFields($payload),
        ]);
        $this->syncTechRelations($user, $payload);

        Mail::to($user->email)->send(new UserCreatedCredentialsMail($user, $plainPassword));

        return redirect()->route('admin.users')->with('status', 'Utilisateur créé et email envoyé.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_unless((bool) $request->user()?->admin, 403);

        $this->normalizeTechTimeInputs($request);

        $payload = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::in([0, 1, 2])],
            'admin' => ['required', 'boolean'],
            'phone' => ['nullable', 'string', 'max:30', 'required_if:role,2'],
            'address' => ['nullable', 'string', 'max:255', 'required_if:role,2'],
            'department_code' => ['nullable', 'string', 'max:3', 'regex:/^(\\d{2,3}|2A|2B)$/i', 'required_if:role,2'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_if:role,2'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_if:role,2'],
            'day_start_time' => ['nullable', 'date_format:H:i', 'required_if:role,2'],
            'day_end_time' => ['nullable', 'date_format:H:i', 'required_if:role,2'],
            'break_duration_minutes' => ['nullable', 'integer', 'min:0', 'max:240', 'required_if:role,2'],
            'service_ids' => ['nullable', 'array', 'required_if:role,2'],
            'service_ids.*' => ['integer', Rule::exists('services', 'id')],
            'department_codes' => ['nullable', 'array', 'required_if:role,2'],
            'department_codes.*' => ['string', Rule::exists('departments', 'code')],
        ]);
        $payload = UserNameNormalizer::normalizePayload($payload);

        if ($request->user()->is($user) && ! (bool) $payload['admin']) {
            return back()->withErrors(['user' => 'Impossible de retirer le statut admin de votre propre compte.']);
        }

        $user->fill([
            'first_name' => $payload['first_name'],
            'last_name' => $payload['last_name'],
            'email' => $payload['email'],
            'role' => (int) $payload['role'],
            'admin' => (bool) $payload['admin'],
            ...$this->resolveTechFields($payload),
        ]);

        $user->save();
        $this->syncTechRelations($user, $payload);

        return redirect()->route('admin.users')->with('status', 'Utilisateur mis à jour.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_unless((bool) $request->user()?->admin, 403);

        if ($request->user()->is($user)) {
            return back()->withErrors(['user' => 'Impossible de désactiver votre propre compte.']);
        }

        $user->delete();

        return redirect()->route('admin.users')->with('status', 'Utilisateur désactivé.');
    }

    public function restore(Request $request, int $user): RedirectResponse
    {
        abort_unless((bool) $request->user()?->admin, 403);

        $target = User::withTrashed()->findOrFail($user);
        $target->restore();

        return redirect()->route('admin.users', ['status' => 'trashed'])->with('status', 'Utilisateur restaure.');
    }

    public function forceDelete(Request $request, int $user): RedirectResponse
    {
        abort_unless((bool) $request->user()?->admin, 403);

        $target = User::withTrashed()->findOrFail($user);

        if (! $target->trashed()) {
            return back()->withErrors(['user' => 'Suppression définitive autorisée uniquement après soft delete.']);
        }

        if ($request->user()->is($target)) {
            return back()->withErrors(['user' => 'Impossible de supprimer définitivement votre propre compte.']);
        }

        $target->forceDelete();

        return redirect()->route('admin.users', ['status' => 'trashed'])->with('status', 'Utilisateur supprimé définitivement.');
    }

    public function sendResetLink(Request $request, int $user): RedirectResponse
    {
        abort_unless((bool) $request->user()?->admin, 403);

        $target = User::withTrashed()->findOrFail($user);

        if ($target->trashed()) {
            return back()->withErrors(['user' => 'Impossible d envoyér un lien de reset à un compte supprimé.']);
        }

        $status = Password::sendResetLink(['email' => $target->email]);

        if ($status !== Password::RESET_LINK_SENT) {
            return back()->withErrors(['user' => __($status)]);
        }

        return redirect()->route('admin.users')->with('status', 'Lien de reinitialisation envoyé.');
    }

    private function resolveTechFields(array $payload): array
    {
        if ((int) $payload['role'] !== 2) {
            return [
                'phone' => null,
                'address' => null,
                'department_code' => null,
                'latitude' => null,
                'longitude' => null,
                'day_start_time' => null,
                'day_end_time' => null,
                'break_duration_minutes' => null,
            ];
        }

        return [
            'phone' => $payload['phone'],
            'address' => $payload['address'],
            'department_code' => mb_strtoupper($payload['department_code']),
            'latitude' => $payload['latitude'],
            'longitude' => $payload['longitude'],
            'day_start_time' => $payload['day_start_time'],
            'day_end_time' => $payload['day_end_time'],
            'break_duration_minutes' => (int) $payload['break_duration_minutes'],
        ];
    }

    private function normalizeTechTimeInputs(Request $request): void
    {
        $normalized = [];

        foreach (['day_start_time', 'day_end_time'] as $field) {
            $value = $request->input($field);

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($value), $matches) !== 1) {
                continue;
            }

            $normalized[$field] = sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2]);
        }

        if ($normalized !== []) {
            $request->merge($normalized);
        }
    }

    private function syncTechRelations(User $user, array $payload): void
    {
        if ((int) $payload['role'] !== 2) {
            $user->services()->sync([]);
            $user->departments()->sync([]);

            return;
        }

        $user->services()->sync(collect($payload['service_ids'] ?? [])->map(fn ($id): int => (int) $id)->unique()->values());
        $user->departments()->sync(collect($payload['department_codes'] ?? [])->map(fn ($code): string => mb_strtoupper((string) $code))->unique()->values());
    }
}
