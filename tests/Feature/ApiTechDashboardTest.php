<?php

use App\Models\WAPetGCUser;
use App\Models\WAPetGCTech;
use Illuminate\Support\Facades\Log;
use function Pest\Laravel\postJson;
use function Pest\Laravel\getJson;

test('un tech peut se connecter en api, consulter ses stats et être supprimé', function () {
    Log::info('📌 Début du test API Tech Dashboard');

    // Générer un email unique à chaque test
    $salt = uniqid();
    $email = "tech_{$salt}@example.com";

    // Création de l'utilisateur tech
    $user = WAPetGCUser::factory()->create([
        'email' => $email,
        'password' => bcrypt('password123'),
        'role' => 'tech',
    ]);
    Log::info('👤 Utilisateur créé', ['id' => $user->id, 'email' => $user->email]);

    // Création du technicien lié
    WAPetGCTech::factory()->create(['user_id' => $user->id]);
    Log::info('🔧 Technicien lié à l\'utilisateur');

    // Connexion API
    $response = postJson('/api/login', [
        'email' => $email,
        'password' => 'password123',
    ]);
    $response->assertOk();
    $token = $response->json('token');

    expect($token)->not()->toBeNull();
    Log::info('🔐 Token reçu', ['token' => $token]);

    // Requête sur les stats
    $statsResponse = getJson('/api/dashboard/stats', [
        'Authorization' => 'Bearer ' . $token,
        'Accept' => 'application/json',
    ]);
    $statsResponse->assertOk();

    $data = $statsResponse->json();
    Log::info('📊 Stats récupérées', $data);

    expect($data)->toHaveKeys([
        'rdvEffectuesAujd',
        'rdvAVenirAujd',
        'rdvEffectuesMois',
        'rdvAVenirMois',
    ]);

    // Suppression de l'utilisateur
    $user->delete();
    Log::info('🗑️ Utilisateur supprimé', ['id' => $user->id]);
});
