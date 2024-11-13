<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\TechController;

// Route d'accueil pour le formulaire de connexion
Route::get('/', [AuthController::class, 'showLoginForm'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.submit');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Routes protégées par l'authentification
Route::middleware(['auth'])->group(function () {

    // Routes administrateur
    Route::get('/admin/manage-user', [AdminController::class, 'manageUser'])->name('admin.manage_user');
    Route::get('/admin/manage-presta', [AdminController::class, 'managePresta'])->name('admin.manage_presta');

    // Routes assistante
    Route::get('/assistant/dashboard', [AssistantController::class, 'dashboard'])->name('assistant.dashboard');
    Route::get('/assistant/prendre-rdv', [AssistantController::class, 'prendreRdv'])->name('assistant.prendre_rdv');
    Route::get('/assistant/agenda-tech', [AssistantController::class, 'agendaTech'])->name('assistant.agenda_tech');

    // Routes technicien
    Route::get('/tech/dashboard', [TechController::class, 'dashboard'])->name('tech.dashboard');
    Route::get('/tech/agenda', [TechController::class, 'agenda'])->name('tech.agenda');
});