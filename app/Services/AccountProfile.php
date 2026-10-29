<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountProfile
{
    public function update(User $user, array $data): void
    {
        abort_if($user->must_change_password, 403);
        $user->forceFill(UserNameNormalizer::normalizePayload(Arr::only($data, ['first_name', 'last_name', 'phone'])))->save();
    }

    public function changePassword(User $user, array $data, ?string $sessionId): void
    {
        abort_if($user->must_change_password, 403);
        DB::transaction(function () use ($user, $data, $sessionId): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_if($locked->must_change_password, 403);
            // Recheck under lock in case the password changed after request validation.
            if (! Hash::check($data['current_password'], $locked->password)) {
                throw ValidationException::withMessages(['current_password' => 'Le mot de passe actuel est incorrect.'])->errorBag('password');
            }
            $locked->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
            if (config('session.driver') === 'database') {
                DB::table('sessions')->where('user_id', $user->id)->when($sessionId, fn ($query) => $query->where('id', '!=', $sessionId))->delete();
            }
            $user->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
