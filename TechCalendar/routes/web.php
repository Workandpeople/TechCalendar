<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\TechController;

Route::get('/test-env', function () {
    return env('MAPBOX_PUBLIC_TOKEN', 'No Token Found');
});

// Route d'accueil pour le formulaire de connexion
Route::get('/', [AuthController::class, 'showLoginForm'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.submit');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Routes protégées par l'authentification
Route::middleware(['auth'])->group(function () {

    // Routes administrateur
    Route::get('/admin/manage-user', [AdminController::class, 'manageUser'])->name('admin.manage_user');
    Route::get('/admin/manage-presta', [AdminController::class, 'managePresta'])->name('admin.manage_presta');

    // Routes pour les opérations de création, modification et de suppression des utilisateurs
    Route::post('/admin/manage-user/update/{id}', [AdminController::class, 'updateUser'])->name('admin.update_user');
    Route::delete('/admin/manage-user/delete/{id}', [AdminController::class, 'deleteUser'])->name('admin.delete_user');
    Route::post('/admin/manage-user/create', [AdminController::class, 'createUser'])->name('admin.create_user');

    // Routes pour les opérations de création, modification et de suppression des prestations
    Route::post('/admin/manage-presta/update/{id}', [AdminController::class, 'updatePresta'])->name('admin.update_presta');
    Route::delete('/admin/manage-presta/delete/{id}', [AdminController::class, 'deletePresta'])->name('admin.delete_presta');
    Route::post('/admin/manage-presta/create', [AdminController::class, 'createPresta'])->name('admin.create_presta');

    // Routes assistante
    Route::get('/assistant/dashboard', [AssistantController::class, 'dashboard'])->name('assistant.dashboard');
    Route::get('/assistant/prendre-rdv', [AssistantController::class, 'prendreRdv'])->name('assistant.prendre_rdv');
    Route::get('/assistant/agenda-tech', [AssistantController::class, 'agendaTech'])->name('assistant.agenda_tech');

    Route::get('/search-technicians', [AssistantController::class, 'searchTechnicians'])->name('search.technicians');
    Route::post('/appointments', [AssistantController::class, 'storeAppointment'])->name('appointments.store');

    // Routes technicien
    Route::get('/tech/dashboard', [TechController::class, 'dashboard'])->name('tech.dashboard');
    Route::get('/tech/agenda', [TechController::class, 'agenda'])->name('tech.agenda');
});