<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{
    AppointmentController,
    ManageUserController,
    AuthController,
    HomeController,
    ManageAppointmentController,
    ManageProviderController,
    StatController,
    TechDashboardController,
    TechCalendarController,
    CalendarController
};

// Routes publiques
Route::get('/', [AuthController::class, 'login'])->name('login'); // Page de connexion
Route::post('/login', [AuthController::class, 'loginSubmit'])->name('login.submit'); // Connexion
Route::post('/logout', [AuthController::class, 'logout'])->name('logout'); // Déconnexion
Route::get('/forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot-password'); // Réinitialisation mot de passe

// Routes protégées par le middleware 'auth'
Route::middleware(['auth'])->group(function () {

    // Routes accessibles uniquement par l'admin
    Route::middleware(['checkRole:admin'])->group(function () {
        // Route pour HomeController
        Route::get('/home', [HomeController::class, 'index'])->name('home.index');
    });

    // Routes accessibles par l'admin et l'assistante
    Route::middleware(['checkRole:admin,assistante'])->group(function () {
        // Route pour AppointmentController
        Route::get('/appointment', [AppointmentController::class, 'index'])->name('appointment.index');
        Route::post('/appointment', [AppointmentController::class, 'store'])->name('appointment.store');
        Route::get('/search-appointments', [AppointmentController::class, 'search'])->name('appointments.search');
        Route::get('/calculate-route', [AppointmentController::class, 'calculateRoute'])->name('appointments.calculate-route');

        // Routes pour ManageUserController
        Route::get('manage-users', [ManageUserController::class, 'index'])->name('manage-users.index');
        Route::put('manage-users/{id}/password', [ManageUserController::class, 'updatePassword'])->name('manage-users.updatePassword');
        Route::get('manage-users/search', [ManageUserController::class, 'search'])->name('manage-users.search');
        Route::get('manage-users/create', [ManageUserController::class, 'create'])->name('manage-users.create');
        Route::post('manage-users', [ManageUserController::class, 'store'])->name('manage-users.store');
        Route::get('manage-users/{id}', [ManageUserController::class, 'show'])->name('manage-users.show');
        Route::get('manage-users/{id}/edit', [ManageUserController::class, 'edit'])->name('manage-users.edit');
        Route::put('manage-users/{id}', [ManageUserController::class, 'update'])->name('manage-users.update');
        Route::delete('manage-users/{id}', [ManageUserController::class, 'destroy'])->name('manage-users.destroy');
        Route::post('manage-users/{id}/restore', [ManageUserController::class, 'restore'])->name('manage-users.restore');
        Route::delete('manage-users/{id}/hard-delete', [ManageUserController::class, 'hardDelete'])->name('manage-users.hard-delete');

        // Routes pour ManageProviderController
        Route::get('manage-providers', [ManageProviderController::class, 'index'])->name('manage-providers.index');
        Route::get('manage-providers/search', [ManageProviderController::class, 'search'])->name('manage-providers.search');
        Route::post('manage-providers', [ManageProviderController::class, 'store'])->name('manage-providers.store');
        Route::get('manage-providers/{id}/edit', [ManageProviderController::class, 'edit'])->name('manage-providers.edit');
        Route::put('manage-providers/{id}', [ManageProviderController::class, 'update'])->name('manage-providers.update');
        Route::delete('manage-providers/{id}', [ManageProviderController::class, 'destroy'])->name('manage-providers.destroy');
        Route::post('manage-providers/{id}/restore', [ManageProviderController::class, 'restore'])->name('manage-providers.restore');
        Route::delete('manage-providers/{id}/hard-delete', [ManageProviderController::class, 'hardDelete'])->name('manage-providers.hard-delete');

        // Routes pour ManageAppointmentController
        Route::get('manage-appointments', [ManageAppointmentController::class, 'index'])->name('manage-appointments.index');
        Route::get('manage-appointments/search', [ManageAppointmentController::class, 'search'])->name('manage-appointments.search');
        Route::get('manage-appointments/create', [ManageAppointmentController::class, 'create'])->name('manage-appointments.create');
        Route::post('manage-appointments', [ManageAppointmentController::class, 'store'])->name('manage-appointments.store');
        Route::get('manage-appointments/{id}', [ManageAppointmentController::class, 'show'])->name('manage-appointments.show');
        Route::get('manage-appointments/{id}/view-client', [ManageAppointmentController::class, 'viewClient'])->name('manage-appointments.view-client');
        Route::put('manage-appointments/{id}/reassign-tech', [ManageAppointmentController::class, 'reassignTech'])->name('manage-appointments.reassign-tech');
        Route::get('manage-appointments/{id}/edit', [ManageAppointmentController::class, 'edit'])->name('manage-appointments.edit');
        Route::put('manage-appointments/{id}', [ManageAppointmentController::class, 'update'])->name('manage-appointments.update');
        Route::delete('manage-appointments/{id}', [ManageAppointmentController::class, 'destroy'])->name('manage-appointments.destroy');
        Route::post('manage-appointments/{id}/restore', [ManageAppointmentController::class, 'restore'])->name('manage-appointments.restore');
        Route::delete('manage-appointments/{id}/hard-delete', [ManageAppointmentController::class, 'hardDelete'])->name('manage-appointments.hard-delete');

        // Route pour CalendarController
        Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');
        Route::get('/api/calendar', [CalendarController::class, 'getAppointments'])->name('calendar.fetch');

        // Route pour StatController
        Route::get('/stats', [StatController::class, 'index'])->name('stats.index');
        Route::get('/tech-search', [StatController::class, 'search'])->name('stats.search');

    });

    // Routes accessibles par l'admin et le technicien
    Route::middleware(['checkRole:tech'])->group(function () {
        // Route pour TechDashboardController
        Route::get('/tech-dashboard', [TechDashboardController::class, 'index'])->name('tech-dashboard.index');
        Route::get('/tech-dashboard/appointments', [TechDashboardController::class, 'getAppointments'])->name('tech-dashboard.appointments');

        // Route pour TechCalendarController
        Route::get('/tech-calendar', [TechCalendarController::class, 'index'])->name('tech-calendar.index');
        Route::get('/tech-calendar/appointments', [TechCalendarController::class, 'getAppointments'])->name('tech-calendar.appointments');
    });
});
