<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\WAPetGCUser;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MobileDashboardController;
use Illuminate\Support\Facades\Auth;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Ici, on retire tout middleware 'auth:sanctum'. Chaque route protégée
| effectuera sa propre vérification “manuelle” du Bearer-token dans le
| contrôleur correspondant.
|
*/

// 1) Route publique pour la connexion (login)
Route::post('/login', [AuthController::class, 'login']);

// 2) Route publique pour le debug de l'en-tête (lecture manuelle du token)
Route::get('/test-header', [AuthController::class, 'testHeader']);

// 3) Dashboard minimal (juste pour vérifier que l'API répond)
Route::get('/ping', function () {
    return response()->json(['pong' => true]);
});

// 4) Routes “protégées” mais sans middleware global : la validation se fait manuellement dans le controller

//  /api/dashboard => message fixe
Route::get('/dashboard', function () {
    return response()->json(['message' => 'Bienvenue sur le dashboard API.']);
});

//  /api/dashboard/stats => renvoie les statistiques, token manuellement vérifié
Route::get('/dashboard/stats', [MobileDashboardController::class, 'stats']);

//  /api/dashboard/appointments => renvoie la liste des rendez-vous, token manuellement vérifié
Route::get('/dashboard/appointments', [MobileDashboardController::class, 'appointments']);

//  /api/dashboard/sync => synchronise les données, token manuellement vérifié
Route::get('/dashboard/sync', [MobileDashboardController::class, 'sync']);
