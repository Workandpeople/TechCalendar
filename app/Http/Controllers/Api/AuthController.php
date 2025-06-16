<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WAPetGCUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        Log::info('Login attempt started', ['request' => $request->all()]);

        try {
            Log::info('Validating credentials');
            $credentials = $request->validate([
                'email' => ['required', 'email'],
                'password' => ['required'],
            ]);

            Log::info('Fetching user by email', ['email' => $credentials['email']]);
            $user = WAPetGCUser::where('email', $credentials['email'])->first();

            if (!$user) {
                Log::warning('User not found', ['email' => $credentials['email']]);
                return response()->json(['message' => 'Identifiants invalides'], 401);
            }

            Log::info('Checking password');
            if (!Hash::check($credentials['password'], $user->password)) {
                Log::warning('Invalid password', ['email' => $credentials['email']]);
                return response()->json(['message' => 'Identifiants invalides'], 401);
            }

            if ($request->filled('onesignal_player_id')) {
                Log::info('Updating OneSignal player ID', ['onesignal_player_id' => $request->onesignal_player_id]);
                $user->onesignal_player_id = $request->onesignal_player_id;
                $user->save();
            }

            Log::info('Checking user role', ['role' => $user->role]);
            if ($user->role !== 'tech') {
                Log::warning('Access denied due to role mismatch', ['role' => $user->role]);
                return response()->json(['message' => 'Accès refusé. Vous n\'avez pas le rôle requis.'], 403);
            }

            Log::info('Creating token for user', ['user_id' => $user->id]);
            $token = $user->createToken('mobile-token')->plainTextToken;

            Log::info('Login successful', ['user_id' => $user->id]);
            return response()->json([
                'token' => $token,
                'user' => $user,
            ]);
        } catch (\Exception $e) {
            Log::error('Login error: ' . $e->getMessage(), [
                'exception' => $e,
                'request' => $request->all(),
            ]);
            return response()->json(['message' => 'Une erreur est survenue lors de la connexion.'], 500);
        }
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();
        return response()->json(['message' => 'Déconnecté avec succès.']);
    }
}
