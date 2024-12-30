<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\GraphController;
use App\Http\Controllers\ManageUsersController;
use App\Http\Controllers\ManageServicesController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\DashboardController;

// Route pour le formulaire de connexion
Route::get('/', [AuthController::class, 'loginView'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.submit');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Routes protégées par l'authentification
Route::middleware(['auth'])->group(function () {
    // Routes administrateur
    Route::get('/admin/graph-user', [GraphController::class, 'graphUser'])->name('admin.graph_user');

    // Routes assistante
    Route::get('/assistant/manage-user', [ManageUsersController::class, 'manageUser'])->name('assistant.manage_user');
    Route::post('/assistant/manage-user', [ManageUsersController::class, 'createUser'])->name('assistant.create_user');
    Route::put('/assistant/manage-user/{id}', [ManageUsersController::class, 'updateUser'])->name('assistant.update_user');
    Route::delete('/assistant/manage-user/{id}', [ManageUsersController::class, 'deleteUser'])->name('assistant.delete_user');

    // Routes pour la gestion des prestations
    Route::get('/assistant/manage-service', [ManageServicesController::class, 'manageService'])->name('assistant.manage_service');
    Route::post('/assistant/manage-service', [ManageServicesController::class, 'createService'])->name('assistant.create_service');
    Route::put('/assistant/manage-service/{id}', [ManageServicesController::class, 'updateService'])->name('assistant.update_service');
    Route::delete('/assistant/manage-service/{id}', [ManageServicesController::class, 'deleteService'])->name('assistant.delete_service');

    Route::get('/assistant/take-appointements', [AppointmentController::class, 'takeAppointement'])->name('assistant.take_appointements');
    Route::get('/assistant/tech-calendar', [CalendarController::class, 'techCalendar'])->name('assistant.tech_calendar');

    // Routes technicien
    Route::get('/tech/dashboard', [DashboardController::class, 'dashboard'])->name('tech.dashboard');
});

//Route::post('/get-user-appointments', [TechController::class, 'getUserAppointments']);

    // Routes pour les opérations de création, modification et de suppression des utilisateurs
    // Route::post('/admin/manage-user/update/{id}', [AdminController::class, 'updateUser'])->name('admin.update_user');
    // Route::delete('/admin/manage-user/delete/{id}', [AdminController::class, 'deleteUser'])->name('admin.delete_user');
    // Route::post('/admin/manage-user/create', [AdminController::class, 'createUser'])->name('admin.create_user');

    // Routes pour les opérations de création, modification et de suppression des prestations
    // Route::post('/admin/manage-presta/update/{id}', [AdminController::class, 'updatePresta'])->name('admin.update_presta');
    // Route::delete('/admin/manage-presta/delete/{id}', [AdminController::class, 'deletePresta'])->name('admin.delete_presta');
    // Route::post('/admin/manage-presta/create', [AdminController::class, 'createPresta'])->name('admin.create_presta');

    // Route::get('/search-technicians', [AssistantController::class, 'searchTechnicians'])->name('search.technicians');
    // Route::post('/appointments', [AssistantController::class, 'storeAppointment'])->name('appointments.store');

    // Route::get('/rendezvous/{id}', [AssistantController::class, 'show'])->name('rendezvous.show');
    // Route::post('/get-technician-appointments', [AssistantController::class, 'getTechnicianAppointments']);