<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MobileAccessToken;
use App\Models\MobilePushToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class MobileAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:80'],
        ], [
            'email.required' => 'Renseigne ton adresse e-mail.',
            'email.email' => 'Renseigne une adresse e-mail valide.',
            'password.required' => 'Renseigne ton mot de passe.',
        ]);

        $user = User::query()
            ->where('email', mb_strtolower($payload['email']))
            ->first();

        if (! $user || ! Hash::check($payload['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'Identifiants invalides.',
            ]);
        }

        if ((int) $user->role !== 2 || (bool) $user->admin) {
            throw ValidationException::withMessages([
                'email' => 'Cette application est réservée aux techniciens.',
            ]);
        }

        $plainToken = 'tc_mobile_'.Str::random(64);
        MobileAccessToken::query()->create([
            'user_id' => $user->id,
            'name' => $payload['device_name'] ?? 'mobile',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => null,
        ]);

        return response()->json([
            'token' => $plainToken,
            'token_type' => 'Bearer',
            'expires_at' => null,
            'user' => $this->serializeUser($user),
        ]);
    }

    public function sendPasswordResetLink(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'email' => ['required', 'email'],
        ], [
            'email.required' => 'Renseigne ton adresse e-mail.',
            'email.email' => 'Renseigne une adresse e-mail valide.',
        ]);

        $email = mb_strtolower($payload['email']);
        $technician = User::query()
            ->where('email', $email)
            ->where('role', 2)
            ->where('admin', false)
            ->first();

        if ($technician) {
            PasswordBroker::sendResetLink(['email' => $technician->email]);
        }

        return response()->json([
            'message' => 'Si un compte technicien actif correspond à cette adresse, un lien de réinitialisation vient d’être envoyé.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->serializeUser($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $accessToken = $request->attributes->get('mobile_access_token');

        if ($accessToken instanceof MobileAccessToken) {
            $accessToken->delete();
        }

        return response()->json([
            'message' => 'Session mobile fermée.',
        ]);
    }

    public function updateFirstPassword(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless((bool) $user, 403);

        $payload = $request->validate([
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ], [
            'password.required' => 'Renseigne ton nouveau mot de passe.',
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
        ]);

        $user->forceFill([
            'password' => Hash::make($payload['password']),
            'must_change_password' => false,
        ])->save();

        return response()->json([
            'message' => 'Mot de passe mis à jour.',
            'user' => $this->serializeUser($user->refresh()),
        ]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless((bool) $user, 403);

        $payload = $request->validate([
            'notification_mail_enabled' => ['required', 'boolean'],
            'notification_push_enabled' => ['required', 'boolean'],
        ]);

        $user->forceFill([
            'notification_mail_enabled' => (bool) $payload['notification_mail_enabled'],
            'notification_push_enabled' => (bool) $payload['notification_push_enabled'],
        ])->save();

        return response()->json([
            'message' => 'Préférences mises à jour.',
            'user' => $this->serializeUser($user->refresh()),
        ]);
    }

    public function storePushToken(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless((bool) $user, 403);

        $payload = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', 'string', 'in:ios,android,unknown'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        MobilePushToken::query()->updateOrCreate(
            ['token' => $payload['token']],
            [
                'user_id' => $user->id,
                'platform' => $payload['platform'],
                'device_name' => $payload['device_name'] ?? null,
                'last_used_at' => now(),
            ],
        );

        return response()->json([
            'message' => 'Token push enregistré.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'full_name' => $user->full_name,
            'initials' => $user->initials,
            'email' => $user->email,
            'must_change_password' => (bool) $user->must_change_password,
            'notification_mail_enabled' => (bool) ($user->notification_mail_enabled ?? true),
            'notification_push_enabled' => (bool) ($user->notification_push_enabled ?? true),
            'phone' => $user->phone,
            'address' => $user->address,
            'department_code' => $user->department_code,
            'day_start_time' => $user->day_start_time,
            'day_end_time' => $user->day_end_time,
        ];
    }
}
