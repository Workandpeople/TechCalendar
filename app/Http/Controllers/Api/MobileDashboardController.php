<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\WAPetGCUser;
use App\Models\WAPetGCAppointment;
use Laravel\Sanctum\PersonalAccessToken;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;

class MobileDashboardController extends Controller
{
    /**
     * GET /api/dashboard/stats
     *
     * Vérification manuelle du Bearer-token puis retour des statistiques.
     */
    public function stats(Request $request)
    {
        try {
            // 1) Récupérer le Bearer-token brut (format "<id>|<secret>")
            $bearer = $request->bearerToken();
            if (! $bearer) {
                Log::warning('stats: Pas de token dans la requête.');
                return response()->json(['error' => 'Pas de token dans la requête.'], 401);
            }

            // 2) Séparer l'ID du token et la partie secrète
            $parts   = explode('|', $bearer, 2);
            $tokenId = $parts[0] ?? null;
            $plain   = $parts[1] ?? null;

            if (! $tokenId || ! $plain) {
                Log::warning("stats: Format de token invalide. Valeur brute: {$bearer}");
                return response()->json(['error' => 'Format de token invalide.'], 401);
            }

            // 3) Charger la ligne correspondante dans personal_access_tokens
            $tokenRow = PersonalAccessToken::find($tokenId);
            if (! $tokenRow) {
                Log::warning("stats: Aucun token trouvé pour id = {$tokenId}");
                return response()->json(['error' => 'Token inconnu.'], 401);
            }

            // 4) Vérifier que la partie “secret” correspond au hash SHA-256 stocké
            // Sanctum stocke "token" sous forme de hash: hash('sha256', $plainTextSecret)
            $hashedPlain = hash('sha256', $plain);
            if (! hash_equals($tokenRow->token, $hashedPlain)) {
                Log::warning("stats: Secret du token ne matche pas pour id {$tokenId}");
                return response()->json(['error' => 'Token invalide (mauvais secret).'], 401);
            }

            // 5) Vérifier que le token est bien attaché à un WAPetGCUser
            if ($tokenRow->tokenable_type !== WAPetGCUser::class) {
                Log::warning("stats: tokenable_type inattendu pour id {$tokenId} : {$tokenRow->tokenable_type}");
                return response()->json(['error' => 'Jeton non associé à un utilisateur valide.'], 401);
            }

            // 6) Récupérer l’utilisateur en base
            $user = WAPetGCUser::find($tokenRow->tokenable_id);
            if (! $user) {
                Log::warning("stats: Utilisateur introuvable pour tokenable_id = {$tokenRow->tokenable_id}");
                return response()->json(['error' => 'Utilisateur introuvable.'], 401);
            }

            // 7) Vérifier que l’utilisateur a le rôle “tech” et une relation tech()
            if ($user->role !== 'tech') {
                Log::warning("stats: Rôle non autorisé pour user_id = {$user->id} (role = {$user->role})");
                return response()->json(['error' => 'Accès refusé, rôle non autorisé.'], 403);
            }

            $tech = $user->tech;
            if (! $tech) {
                Log::warning("stats: Aucune relation tech() pour user_id = {$user->id}");
                return response()->json(['error' => 'Aucun technicien lié à cet utilisateur.'], 403);
            }

            // 8) À partir d’ici, l’utilisateur est authentifié et a un “tech”
            $techId = $tech->id;
            $today      = Carbon::today();
            $startMonth = Carbon::now()->startOfMonth();
            $endMonth   = Carbon::now()->endOfMonth();

            $rdvEffectuesAujd = WAPetGCAppointment::where('tech_id', $techId)
                ->whereDate('start_at', $today)
                ->where('start_at', '<', now())
                ->count();

            $rdvAVenirAujd = WAPetGCAppointment::where('tech_id', $techId)
                ->whereDate('start_at', $today)
                ->where('start_at', '>=', now())
                ->count();

            $rdvEffectuesMois = WAPetGCAppointment::where('tech_id', $techId)
                ->whereBetween('start_at', [$startMonth, $endMonth])
                ->where('start_at', '<', now())
                ->count();

            $rdvAVenirMois = WAPetGCAppointment::where('tech_id', $techId)
                ->whereBetween('start_at', [$startMonth, $endMonth])
                ->where('start_at', '>=', now())
                ->count();

            return response()->json([
                'rdvEffectuesAujd' => $rdvEffectuesAujd,
                'rdvAVenirAujd'    => $rdvAVenirAujd,
                'rdvEffectuesMois' => $rdvEffectuesMois,
                'rdvAVenirMois'    => $rdvAVenirMois,
            ]);
        }
        catch (\Throwable $e) {
            // Enregistrer l'exception complète dans les logs Laravel
            Log::error('Error in MobileDashboardController@stats', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Une erreur est survenue.'], 500);
        }
    }

    public function appointments(Request $request)
    {
        Log::info('appointments: Début de la récupération des rendez-vous');
        try {
            // 1) Récupérer le Bearer-token
            $bearer = $request->bearerToken();
            if (! $bearer) {
                Log::warning('appointments: Pas de token dans la requête.');
                return response()->json(['error' => 'Pas de token dans la requête.'], 401);
            }

            // 2) Séparer l'ID du token et le secret
            $parts   = explode('|', $bearer, 2);
            $tokenId = $parts[0] ?? null;
            $plain   = $parts[1] ?? null;

            if (! $tokenId || ! $plain) {
                Log::warning("appointments: Format de token invalide. Valeur brute: {$bearer}");
                return response()->json(['error' => 'Format de token invalide.'], 401);
            }

            // 3) Charger la ligne personal_access_tokens
            $tokenRow = PersonalAccessToken::find($tokenId);
            if (! $tokenRow) {
                Log::warning("appointments: Aucun token trouvé pour id = {$tokenId}");
                return response()->json(['error' => 'Token inconnu.'], 401);
            }

            // 4) Vérifier le secret via SHA-256
            $hashedPlain = hash('sha256', $plain);
            if (! hash_equals($tokenRow->token, $hashedPlain)) {
                Log::warning("appointments: Secret du token ne matche pas pour id {$tokenId}");
                return response()->json(['error' => 'Token invalide (mauvais secret).'], 401);
            }

            // 5) Vérifier que c’est un WAPetGCUser
            if ($tokenRow->tokenable_type !== WAPetGCUser::class) {
                Log::warning("appointments: tokenable_type inattendu pour id {$tokenId} : {$tokenRow->tokenable_type}");
                return response()->json(['error' => 'Jeton non associé à un utilisateur valide.'], 401);
            }

            // 6) Récupérer l’utilisateur
            $user = WAPetGCUser::find($tokenRow->tokenable_id);
            if (! $user) {
                Log::warning("appointments: Utilisateur introuvable pour tokenable_id = {$tokenRow->tokenable_id}");
                return response()->json(['error' => 'Utilisateur introuvable.'], 401);
            }

            // 7) Vérifier le rôle “tech” et la relation
            if ($user->role !== 'tech') {
                Log::warning("appointments: Rôle non autorisé pour user_id = {$user->id} (role = {$user->role})");
                return response()->json(['error' => 'Accès refusé, rôle non autorisé.'], 403);
            }
            $tech = $user->tech;
            if (! $tech) {
                Log::warning("appointments: Aucune relation tech() pour user_id = {$user->id}");
                return response()->json(['error' => 'Aucun technicien lié à cet utilisateur.'], 403);
            }

            // 8) Préparer la date filtrée (paramètre “date” ou aujourd’hui)
            $date = Carbon::parse($request->query('date', now()));

            // 9) Récupérer les rendez-vous pour cette date
            $appointments = WAPetGCAppointment::where('tech_id', $tech->id)
                ->whereDate('start_at', $date)
                ->orderBy('start_at')
                ->get()
                ->map(function ($a) {
                    // parser manuellement start_at et end_at pour éviter l’erreur
                    $startCarbon = Carbon::parse($a->start_at);
                    $endCarbon   = Carbon::parse($a->end_at);

                    return [
                        'id'          => $a->id,
                        'client_name' => "{$a->client_fname} {$a->client_lname}",
                        'start_at'    => $startCarbon->format('H:i'),
                        'end_at'      => $endCarbon->format('H:i'),
                        'service'     => optional($a->service)->name,
                        'comment'     => $a->comment,
                        'address'     => "{$a->client_adresse}, {$a->client_zip_code} {$a->client_city}",
                        'phone'       => $a->client_phone,
                    ];
                });

            return response()->json($appointments);
        }
        catch (\Throwable $e) {
            // Journaliser la pile d’erreurs
            Log::error('Error in MobileDashboardController@appointments', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Une erreur est survenue.'], 500);
        }
    }

    public function sync(Request $request)
    {
        Log::info('sync: Début de la synchronisation des rendez-vous');
        try {
            // 1) Récupérer le Bearer-token brut
            $bearer = $request->bearerToken();
            if (! $bearer) {
                Log::warning('sync: Pas de token dans la requête.');
                return response()->json([
                    'success' => false,
                    'message' => 'Pas de token dans la requête.'
                ], 401);
            }

            // 2) Séparer l’ID du token et la partie secrète
            $parts   = explode('|', $bearer, 2);
            $tokenId = $parts[0] ?? null;
            $plain   = $parts[1] ?? null;
            if (! $tokenId || ! $plain) {
                Log::warning("sync: Format de token invalide. Valeur brute: {$bearer}");
                return response()->json([
                    'success' => false,
                    'message' => 'Format de token invalide.'
                ], 401);
            }

            // 3) Charger la ligne correspondante dans personal_access_tokens
            $tokenRow = PersonalAccessToken::find($tokenId);
            if (! $tokenRow) {
                Log::warning("sync: Aucun token trouvé pour id = {$tokenId}");
                return response()->json([
                    'success' => false,
                    'message' => 'Token inconnu.'
                ], 401);
            }

            // 4) Vérifier que la partie “secret” correspond au hash SHA-256
            $hashedPlain = hash('sha256', $plain);
            if (! hash_equals($tokenRow->token, $hashedPlain)) {
                Log::warning("sync: Secret du token ne matche pas pour id {$tokenId}");
                return response()->json([
                    'success' => false,
                    'message' => 'Token invalide (mauvais secret).'
                ], 401);
            }

            // 5) Vérifier que le token est attaché à un WAPetGCUser
            if ($tokenRow->tokenable_type !== WAPetGCUser::class) {
                Log::warning("sync: tokenable_type inattendu pour id {$tokenId} : {$tokenRow->tokenable_type}");
                return response()->json([
                    'success' => false,
                    'message' => 'Jeton non associé à un utilisateur valide.'
                ], 401);
            }

            // 6) Récupérer l’utilisateur en base
            $user = WAPetGCUser::find($tokenRow->tokenable_id);
            if (! $user) {
                Log::warning("sync: Utilisateur introuvable pour tokenable_id = {$tokenRow->tokenable_id}");
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur introuvable.'
                ], 401);
            }

            // 7) Vérifier que l’utilisateur a le rôle “tech” et une relation tech()
            if ($user->role !== 'tech') {
                Log::warning("sync: Rôle non autorisé pour user_id = {$user->id} (role = {$user->role})");
                return response()->json([
                    'success' => false,
                    'message' => 'Accès refusé, rôle non autorisé.'
                ], 403);
            }
            $tech = $user->tech;
            if (! $tech) {
                Log::warning("sync: Aucune relation tech() pour user_id = {$user->id}");
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun technicien trouvé.'
                ], 403);
            }

            // 8) Récupérer tous les rendez-vous futurs
            $techId = $tech->id;
            $appointments = WAPetGCAppointment::where('tech_id', $techId)
                ->where('start_at', '>=', Carbon::now())
                ->get();

            Log::info("Génération ICS pour {$appointments->count()} RDV (tech_id: $techId)");

            // 9) Construire le contenu ICS
            $icsContent = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//TechCalendar//FR\n";
            foreach ($appointments as $appt) {
                $start = Carbon::parse($appt->start_at)->format('Ymd\THis');
                $end   = Carbon::parse($appt->end_at)->format('Ymd\THis');

                $icsContent .= "BEGIN:VEVENT\n";
                $icsContent .= "UID:appt-{$appt->id}@techcalendar\n";
                $icsContent .= "DTSTAMP:" . Carbon::now()->format('Ymd\THis') . "\n";
                $icsContent .= "DTSTART:{$start}\n";
                $icsContent .= "DTEND:{$end}\n";
                $icsContent .= "SUMMARY:RDV avec {$appt->client_fname} {$appt->client_lname}\n";
                $icsContent .= "LOCATION:{$appt->client_adresse}, {$appt->client_zip_code} {$appt->client_city}\n";
                $icsContent .= "DESCRIPTION:Technicien: {$appt->tech->user->prenom} {$appt->tech->user->nom}\n";
                $icsContent .= "END:VEVENT\n";
            }
            $icsContent .= "END:VCALENDAR";

            // 10) Sauvegarder le fichier ICS dans storage/app/public
            $fileName = "rendez-vous-{$techId}.ics";
            Storage::disk('public')->put($fileName, $icsContent);

            // 11) Générer l’URL publique (storage link créé)
            $url = asset("storage/{$fileName}");

            return response()->json([
                'success' => true,
                'url'     => $url,
            ], 200);
        }
        catch (\Throwable $e) {
            Log::error('Error in MobileDashboardController@sync', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue.'
            ], 500);
        }
    }
}
