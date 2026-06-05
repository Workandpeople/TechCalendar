<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class FirstLoginPasswordController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless((bool) $user, 403);

        if (! $user->must_change_password) {
            return back();
        }

        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
        ])->save();

        return back()->with('status', 'Mot de passe mis a jour.');
    }
}
