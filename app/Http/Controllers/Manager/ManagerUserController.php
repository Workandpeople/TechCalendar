<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Mail\UserCreatedCredentialsMail;
use App\Models\Department;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ManagerUserController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($this->canManageUsers($request), 403);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'role' => ['nullable', Rule::in(['0', '1', '2'])],
            'status' => ['nullable', Rule::in(['active', 'trashed', 'all'])],
        ]);

        $query = User::query()->with(['services:id', 'departments:code'])->where('admin', false);
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

        if (array_key_exists('role', $validated)) {
            $query->where('role', (int) $validated['role']);
        }

        $users = $query->orderByDesc('id')->paginate(15)->withQueryString();

        return view('manager.users.index', [
            'users' => $users,
            'services' => Service::query()->orderBy('type')->orderBy('name')->get(['id', 'type', 'name']),
            'departments' => Department::query()->orderBy('code')->get(['code', 'name']),
            'filters' => [
                'q' => $validated['q'] ?? '',
                'role' => $validated['role'] ?? '',
                'status' => $status,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->canManageUsers($request), 403);

        $payload = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'role' => ['required', Rule::in([0, 1, 2])],
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

        $plainPassword = Str::password(10);
        $user = User::query()->create([
            'first_name' => $payload['first_name'],
            'last_name' => $payload['last_name'],
            'email' => $payload['email'],
            'role' => (int) $payload['role'],
            'admin' => false,
            'password' => Hash::make($plainPassword),
            'must_change_password' => true,
            ...$this->resolveTechFields($payload),
        ]);
        $this->syncTechRelations($user, $payload);

        Mail::to($user->email)->send(new UserCreatedCredentialsMail($user, $plainPassword));

        return redirect()->route('manager.users')->with('status', 'Utilisateur cree et email envoye.');
    }

    public function update(Request $request, int $user): RedirectResponse
    {
        abort_unless($this->canManageUsers($request), 403);

        $target = User::withTrashed()->where('admin', false)->findOrFail($user);

        $payload = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($target->id)],
            'role' => ['required', Rule::in([0, 1, 2])],
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

        $target->fill([
            'first_name' => $payload['first_name'],
            'last_name' => $payload['last_name'],
            'email' => $payload['email'],
            'role' => (int) $payload['role'],
            'admin' => false,
            ...$this->resolveTechFields($payload),
        ]);

        $target->save();
        $this->syncTechRelations($target, $payload);

        return redirect()->route('manager.users')->with('status', 'Utilisateur mis a jour.');
    }

    public function destroy(Request $request, int $user): RedirectResponse
    {
        abort_unless($this->canManageUsers($request), 403);

        $target = User::where('admin', false)->findOrFail($user);

        if ($request->user()->is($target)) {
            return back()->withErrors(['user' => 'Impossible de desactiver votre propre compte.']);
        }

        $target->delete();

        return redirect()->route('manager.users')->with('status', 'Utilisateur desactive (soft delete).');
    }

    public function restore(Request $request, int $user): RedirectResponse
    {
        abort_unless($this->canManageUsers($request), 403);

        $target = User::withTrashed()->where('admin', false)->findOrFail($user);
        $target->restore();

        return redirect()->route('manager.users', ['status' => 'trashed'])->with('status', 'Utilisateur restaure.');
    }

    public function forceDelete(Request $request, int $user): RedirectResponse
    {
        abort_unless($this->canManageUsers($request), 403);

        $target = User::withTrashed()->where('admin', false)->findOrFail($user);

        if (! $target->trashed()) {
            return back()->withErrors(['user' => 'Suppression definitive autorisee uniquement apres soft delete.']);
        }

        if ($request->user()->is($target)) {
            return back()->withErrors(['user' => 'Impossible de supprimer definitivement votre propre compte.']);
        }

        $target->forceDelete();

        return redirect()->route('manager.users', ['status' => 'trashed'])->with('status', 'Utilisateur supprime definitivement.');
    }

    public function sendResetLink(Request $request, int $user): RedirectResponse
    {
        abort_unless($this->canManageUsers($request), 403);

        $target = User::withTrashed()->where('admin', false)->findOrFail($user);

        if ($target->trashed()) {
            return back()->withErrors(['user' => 'Impossible d envoyer un lien de reset a un compte supprime.']);
        }

        $status = Password::sendResetLink(['email' => $target->email]);

        if ($status !== Password::RESET_LINK_SENT) {
            return back()->withErrors(['user' => __($status)]);
        }

        return redirect()->route('manager.users')->with('status', 'Lien de reinitialisation envoye.');
    }

    private function canManageUsers(Request $request): bool
    {
        $user = $request->user();

        return (bool) $user && ($user->admin || $user->role === 0);
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
