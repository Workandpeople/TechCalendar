<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminSettingController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Account\FirstLoginPasswordController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Manager\ManagerDashboardController;
use App\Http\Controllers\Manager\ManagerLotController;
use App\Http\Controllers\Manager\ManagerServiceController;
use App\Http\Controllers\Manager\ManagerUserController;
use App\Http\Controllers\Planner\PlannerBookingController;
use App\Http\Controllers\Planner\PlannerDashboardController;
use App\Http\Controllers\Planner\PlannerTrackingController;
use App\Http\Controllers\Tech\TechPlanningController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');

    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])
        ->name('password.store');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/', function () {
        $user = auth()->user();

        if ($user->admin) {
            return redirect()->route('admin.dashboard');
        }

        if ($user->role === 0) {
            return redirect()->route('manager.dashboard');
        }

        if ($user->role === 1) {
            return redirect()->route('planner.dashboard');
        }

        return redirect()->route('tech.planning');
    })->name('dashboard');

    Route::view('/profile', 'app.page', [
        'section' => 'Compte',
        'title' => 'Mon profil',
        'description' => 'Page profil en cours de construction.',
    ])->name('profile');
    Route::post('/account/first-password', [FirstLoginPasswordController::class, 'update'])
        ->name('account.first-password.update');

    Route::get('/admin/dashboard', AdminDashboardController::class)->name('admin.dashboard');

    Route::get('/admin/users', [AdminUserController::class, 'index'])->name('admin.users');
    Route::post('/admin/users', [AdminUserController::class, 'store'])->name('admin.users.store');
    Route::put('/admin/users/{user}', [AdminUserController::class, 'update'])->name('admin.users.update');
    Route::delete('/admin/users/{user}', [AdminUserController::class, 'destroy'])->name('admin.users.destroy');
    Route::post('/admin/users/{user}/restore', [AdminUserController::class, 'restore'])->name('admin.users.restore');
    Route::delete('/admin/users/{user}/force', [AdminUserController::class, 'forceDelete'])->name('admin.users.force-delete');
    Route::post('/admin/users/{user}/send-reset-link', [AdminUserController::class, 'sendResetLink'])->name('admin.users.send-reset-link');

    Route::get('/admin/settings', [AdminSettingController::class, 'index'])->name('admin.settings');
    Route::put('/admin/settings', [AdminSettingController::class, 'update'])->name('admin.settings.update');
    Route::delete('/admin/settings', [AdminSettingController::class, 'destroy'])->name('admin.settings.destroy');

    Route::get('/manager/dashboard', ManagerDashboardController::class)->name('manager.dashboard');
    Route::get('/manager/dashboard/data', [ManagerDashboardController::class, 'data'])->name('manager.dashboard.data');
    Route::post('/manager/dashboard/refresh', [ManagerDashboardController::class, 'refresh'])->name('manager.dashboard.refresh');
    Route::get('/manager/users', [ManagerUserController::class, 'index'])->name('manager.users');
    Route::post('/manager/users', [ManagerUserController::class, 'store'])->name('manager.users.store');
    Route::put('/manager/users/{user}', [ManagerUserController::class, 'update'])->name('manager.users.update');
    Route::delete('/manager/users/{user}', [ManagerUserController::class, 'destroy'])->name('manager.users.destroy');
    Route::post('/manager/users/{user}/restore', [ManagerUserController::class, 'restore'])->name('manager.users.restore');
    Route::delete('/manager/users/{user}/force', [ManagerUserController::class, 'forceDelete'])->name('manager.users.force-delete');
    Route::post('/manager/users/{user}/send-reset-link', [ManagerUserController::class, 'sendResetLink'])->name('manager.users.send-reset-link');
    Route::post('/manager/users/{user}/absences', [ManagerUserController::class, 'storeAbsence'])->name('manager.users.absences.store');
    Route::delete('/manager/users/{user}/absences/{absence}', [ManagerUserController::class, 'destroyAbsence'])->name('manager.users.absences.destroy');
    Route::get('/manager/services', [ManagerServiceController::class, 'index'])->name('manager.services');
    Route::post('/manager/services', [ManagerServiceController::class, 'store'])->name('manager.services.store');
    Route::put('/manager/services/{service}', [ManagerServiceController::class, 'update'])->name('manager.services.update');
    Route::delete('/manager/services/{service}', [ManagerServiceController::class, 'destroy'])->name('manager.services.destroy');
    Route::get('/manager/lots', [ManagerLotController::class, 'index'])->name('manager.lots');
    Route::post('/manager/lots', [ManagerLotController::class, 'store'])->name('manager.lots.store');
    Route::post('/manager/lots/imports', [ManagerLotController::class, 'startImport'])->name('manager.lots.imports.store');
    Route::get('/manager/lots/imports/{preview}', [ManagerLotController::class, 'importStatus'])->name('manager.lots.imports.show');
    Route::post('/manager/lots/imports/{preview}/retry', [ManagerLotController::class, 'retryImport'])->name('manager.lots.imports.retry');
    Route::patch('/manager/lots/imports/{preview}/rows/{rowNumber}', [ManagerLotController::class, 'updateImportRow'])->name('manager.lots.imports.rows.update');
    Route::post('/manager/lots/imports/{preview}/confirm', [ManagerLotController::class, 'confirmImport'])->name('manager.lots.imports.confirm');
    Route::get('/manager/lots/{lot}/download', [ManagerLotController::class, 'download'])->name('manager.lots.download');
    Route::get('/manager/appointments', [PlannerTrackingController::class, 'index'])->name('manager.appointments');

    Route::get('/planner/dashboard', PlannerDashboardController::class)->name('planner.dashboard');
    Route::get('/planner/book', [PlannerBookingController::class, 'index'])->name('planner.book');
    Route::post('/planner/book/analyze', [PlannerBookingController::class, 'analyze'])->name('planner.book.analyze');
    Route::post('/planner/book/technicians/search', [PlannerBookingController::class, 'searchTechnicians'])->name('planner.book.technicians.search');
    Route::post('/planner/book/calendar-window', [PlannerBookingController::class, 'calendarWindow'])->name('planner.book.calendar-window');
    Route::post('/planner/book/appointments', [PlannerBookingController::class, 'store'])->name('planner.book.appointments.store');
    Route::get('/planner/tracking', [PlannerTrackingController::class, 'index'])->name('planner.tracking');
    Route::post('/planner/tracking/events', [PlannerTrackingController::class, 'events'])->name('planner.tracking.events');
    Route::patch('/planner/tracking/appointments/{appointment}/comment', [PlannerTrackingController::class, 'updateComment'])
        ->name('planner.tracking.appointments.comment');
    Route::patch('/planner/tracking/appointments/{appointment}/technician', [PlannerTrackingController::class, 'reassignTechnician'])
        ->name('planner.tracking.appointments.technician');
    Route::delete('/planner/tracking/appointments/{appointment}', [PlannerTrackingController::class, 'destroy'])
        ->name('planner.tracking.appointments.destroy');
    Route::post('/planner/tracking/appointments/{appointment}/restore', [PlannerTrackingController::class, 'restore'])
        ->name('planner.tracking.appointments.restore');

    Route::get('/tech/planning', [TechPlanningController::class, 'index'])->name('tech.planning');
    Route::post('/tech/planning/events', [TechPlanningController::class, 'events'])->name('tech.planning.events');

    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
