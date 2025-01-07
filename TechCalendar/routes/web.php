<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\GraphController;
use App\Http\Controllers\ManageUsersController;
use App\Http\Controllers\ManageServicesController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\OneCalendarController;
use App\Http\Controllers\DashboardController;

// Route pour le formulaire de connexion
Route::get('/', [AuthController::class, 'loginView'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.submit');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Routes protégées par l'authentification
Route::middleware(['auth'])->group(function () {
    // Routes graphiques
    Route::get('/admin/graph-user', [GraphController::class, 'graphUser'])->name('admin.graph_user');
    Route::get('/api/technicians', [GraphController::class, 'getTechnicians'])->name('api.technicians');
    Route::post('/api/technician-stats', [GraphController::class, 'getTechnicianStats'])->name('api.technician_stats');

    // Routes pour la gestion des utilisateurs
    Route::get('/assistant/manage-user', [ManageUsersController::class, 'manageUser'])->name('assistant.manage_user');
    Route::post('/assistant/manage-user', [ManageUsersController::class, 'createUser'])->name('assistant.create_user');
    Route::put('/assistant/manage-user/{id}', [ManageUsersController::class, 'updateUser'])->name('assistant.update_user');
    Route::delete('/assistant/manage-user/{id}', [ManageUsersController::class, 'deleteUser'])->name('assistant.delete_user');
    Route::post('/assistant/manage-user/{id}/restore', [ManageUsersController::class, 'restoreUser'])->name('assistant.restore_user');

    // Routes pour la gestion des prestations
    Route::get('/assistant/manage-service', [ManageServicesController::class, 'manageService'])->name('assistant.manage_service');
    Route::post('/assistant/manage-service', [ManageServicesController::class, 'createService'])->name('assistant.create_service');
    Route::put('/assistant/manage-service/{id}', [ManageServicesController::class, 'updateService'])->name('assistant.update_service');
    Route::delete('/assistant/manage-service/{id}', [ManageServicesController::class, 'deleteService'])->name('assistant.delete_service');

    // Routes pour les rendez-vous
    Route::get('/assistant/take-appointements', [AppointmentController::class, 'takeAppointement'])->name('assistant.take_appointements');
    Route::post('/assistant/submit-appointment', [AppointmentController::class, 'submitAppointment'])->name('assistant.submit_appointment');
    Route::post('/assistant/manual-appointment', [AppointmentController::class, 'manualAppointment'])->name('assistant.manual_appointment');

    // Routes pour la gestion de l'agenda comparatif
    Route::get('/assistant/tech-calendar', [CalendarController::class, 'techCalendar'])->name('assistant.tech_calendar');
    Route::post('/assistant/calendar-events', [CalendarController::class, 'getCalendarEvents'])->name('assistant.calendar_events');    

    // Routes pour la gestion de l'agenda d'un technicien
    Route::get('/assistant/single-tech-schedule', [OneCalendarController::class, 'singleTechSchedule'])->name('assistant.single_tech_schedule');
    Route::get('/assistant/search-technicians', [OneCalendarController::class, 'searchTechnicians'])->name('assistant.search_technicians');
    Route::get('/assistant/tech-appointments', [OneCalendarController::class, 'getTechAppointments'])->name('assistant.tech_appointments');

    // Routes technicien
    Route::get('/tech/dashboard', [DashboardController::class, 'index'])->name('tech.dashboard');
    Route::get('/tech/appointments', [DashboardController::class, 'getAppointments'])->name('tech.getAppointments');
});