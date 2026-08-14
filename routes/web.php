<?php

use App\Http\Controllers\Account\FirstLoginPasswordController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminSettingController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Manager\ManagerDashboardController;
use App\Http\Controllers\Manager\ManagerDelegataireController;
use App\Http\Controllers\Manager\ManagerLotController;
use App\Http\Controllers\Manager\ManagerMailTemplateController;
use App\Http\Controllers\Manager\ManagerServiceController;
use App\Http\Controllers\Manager\ManagerUserController;
use App\Http\Controllers\Planner\PlannerAppointmentMailController;
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
    Route::post('/admin/dashboard/health/run', [AdminDashboardController::class, 'runHealthCheck'])->name('admin.dashboard.health.run');
    Route::post('/admin/dashboard/logs/clear', [AdminDashboardController::class, 'clearLogs'])->name('admin.dashboard.logs.clear');
    Route::get('/admin/dashboard/coffrac/logs', [AdminDashboardController::class, 'coffracLogs'])->name('admin.dashboard.coffrac.logs');
    Route::post('/admin/dashboard/tests/run', [AdminDashboardController::class, 'runTests'])->name('admin.dashboard.tests.run');

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
    Route::delete('/admin/settings/external-appointments', [AdminSettingController::class, 'resetExternalAppointments'])->name('admin.settings.external-appointments.reset');

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
    Route::get('/manager/delegataires', [ManagerDelegataireController::class, 'index'])->name('manager.delegataires');
    Route::post('/manager/delegataires/sync', [ManagerDelegataireController::class, 'sync'])->name('manager.delegataires.sync');
    Route::get('/manager/mail-templates', [ManagerMailTemplateController::class, 'index'])->name('manager.mail-templates');
    Route::post('/manager/mail-templates/senders', [ManagerMailTemplateController::class, 'storeSender'])->name('manager.mail-templates.senders.store');
    Route::put('/manager/mail-templates/senders/{mailSender}', [ManagerMailTemplateController::class, 'updateSender'])->name('manager.mail-templates.senders.update');
    Route::delete('/manager/mail-templates/senders/{mailSender}', [ManagerMailTemplateController::class, 'destroySender'])->name('manager.mail-templates.senders.destroy');
    Route::post('/manager/mail-templates', [ManagerMailTemplateController::class, 'store'])->name('manager.mail-templates.store');
    Route::put('/manager/mail-templates/{mailTemplate}', [ManagerMailTemplateController::class, 'update'])->name('manager.mail-templates.update');
    Route::delete('/manager/mail-templates/{mailTemplate}', [ManagerMailTemplateController::class, 'destroy'])->name('manager.mail-templates.destroy');
    Route::post('/manager/mail-templates/preview', [ManagerMailTemplateController::class, 'preview'])->name('manager.mail-templates.preview');
    Route::get('/manager/services', [ManagerServiceController::class, 'index'])->name('manager.services');
    Route::post('/manager/services', [ManagerServiceController::class, 'store'])->name('manager.services.store');
    Route::put('/manager/services/{service}', [ManagerServiceController::class, 'update'])->name('manager.services.update');
    Route::delete('/manager/services/{service}', [ManagerServiceController::class, 'destroy'])->name('manager.services.destroy');
    Route::get('/manager/lots', [ManagerLotController::class, 'index'])->name('manager.lots');
    Route::post('/manager/lots', [ManagerLotController::class, 'store'])->name('manager.lots.store');
    Route::patch('/manager/lots/{lot}', [ManagerLotController::class, 'update'])->name('manager.lots.update');
    Route::patch('/manager/lots/{lot}/appointment-targets', [ManagerLotController::class, 'updateAppointmentTargets'])->name('manager.lots.appointment-targets.update');
    Route::delete('/manager/lots/{lot}', [ManagerLotController::class, 'destroy'])->name('manager.lots.destroy');
    Route::post('/manager/lots/imports', [ManagerLotController::class, 'startImport'])->name('manager.lots.imports.store');
    Route::get('/manager/lots/imports/{preview}', [ManagerLotController::class, 'importStatus'])->name('manager.lots.imports.show');
    Route::post('/manager/lots/imports/{preview}/retry', [ManagerLotController::class, 'retryImport'])->name('manager.lots.imports.retry');
    Route::patch('/manager/lots/imports/{preview}/rows/{rowNumber}', [ManagerLotController::class, 'updateImportRow'])->name('manager.lots.imports.rows.update');
    Route::post('/manager/lots/imports/{preview}/confirm', [ManagerLotController::class, 'confirmImport'])->name('manager.lots.imports.confirm');
    Route::get('/manager/lots/{lot}/documents', [ManagerLotController::class, 'documents'])->name('manager.lots.documents.index');
    Route::patch('/manager/lots/appointments/{lotAppointment}/visits', [ManagerLotController::class, 'updateAppointmentVisits'])->name('manager.lots.appointments.visits.update');
    Route::patch('/manager/lots/appointments/{lotAppointment}/stats-exclusion', [ManagerLotController::class, 'updateAppointmentStatsExclusion'])->name('manager.lots.appointments.stats-exclusion.update');
    Route::patch('/manager/lots/appointments/{lotAppointment}/global-plus', [ManagerLotController::class, 'updateAppointmentGlobalPlus'])->name('manager.lots.appointments.global-plus.update');
    Route::patch('/manager/lots/appointments/{lotAppointment}/reset-processing', [ManagerLotController::class, 'resetAppointmentProcessing'])->name('manager.lots.appointments.reset-processing');
    Route::post('/manager/lots/appointments/{lotAppointment}/documents', [ManagerLotController::class, 'storeAppointmentDocument'])->name('manager.lots.appointments.documents.store');
    Route::patch('/manager/lots/appointments/documents/{document}', [ManagerLotController::class, 'updateAppointmentDocument'])->name('manager.lots.appointments.documents.update');
    Route::delete('/manager/lots/appointments/documents/{document}', [ManagerLotController::class, 'destroyAppointmentDocument'])->name('manager.lots.appointments.documents.destroy');
    Route::patch('/manager/lots/appointments/{lotAppointment}', [ManagerLotController::class, 'updateAppointment'])->name('manager.lots.appointments.update');
    Route::get('/manager/lots/{lot}/download', [ManagerLotController::class, 'download'])->name('manager.lots.download');
    Route::get('/manager/lots/{lot}', [ManagerLotController::class, 'show'])->name('manager.lots.show');
    Route::get('/manager/appointments', [PlannerTrackingController::class, 'index'])->name('manager.appointments');
    Route::get('/manager/appointments/modify', [PlannerBookingController::class, 'index'])->name('manager.appointments.modify');
    Route::post('/manager/appointments/search', [PlannerTrackingController::class, 'search'])->name('manager.appointments.search');
    Route::get('/manager/appointments/coffrac/placed/status', [PlannerTrackingController::class, 'placedCoffracSyncStatus'])->name('manager.appointments.coffrac.placed.status');
    Route::post('/manager/appointments/coffrac/placed/refresh', [PlannerTrackingController::class, 'refreshPlacedCoffracAppointments'])->name('manager.appointments.coffrac.placed.refresh');

    Route::get('/planner/dashboard', PlannerDashboardController::class)->name('planner.dashboard');
    Route::get('/planner/book', [PlannerBookingController::class, 'index'])->name('planner.book');
    Route::get('/planner/appointments/modify', [PlannerBookingController::class, 'index'])->name('planner.appointments.modify');
    Route::get('/planner/book/crm-appointments', [PlannerBookingController::class, 'crmAppointments'])->name('planner.book.crm-appointments.index');
    Route::post('/planner/book/crm-appointments/refresh', [PlannerBookingController::class, 'refreshCrmAppointments'])->name('planner.book.crm-appointments.refresh');
    Route::post('/planner/book/crm-appointments/{crmAppointmentId}/refresh', [PlannerBookingController::class, 'refreshCrmAppointment'])->name('planner.book.crm-appointments.refresh-one');
    Route::patch('/planner/book/crm-appointments/{crmAppointmentId}', [PlannerBookingController::class, 'updateCrmAppointment'])->name('planner.book.crm-appointments.update');
    Route::post('/planner/book/crm-appointments/{crmAppointmentId}/problem', [PlannerBookingController::class, 'markCrmAppointmentProblem'])->name('planner.book.crm-appointments.problem');
    Route::post('/planner/book/analyze', [PlannerBookingController::class, 'analyze'])->name('planner.book.analyze');
    Route::post('/planner/book/technicians/search', [PlannerBookingController::class, 'searchTechnicians'])->name('planner.book.technicians.search');
    Route::post('/planner/book/calendar-window', [PlannerBookingController::class, 'calendarWindow'])->name('planner.book.calendar-window');
    Route::post('/planner/book/lots/appointments/{lotAppointment}/contact', [PlannerBookingController::class, 'processLotContactAppointment'])->name('planner.book.lots.appointments.contact');
    Route::post('/planner/book/appointments', [PlannerBookingController::class, 'store'])->name('planner.book.appointments.store');
    Route::post('/planner/book/appointments/{appointment}/mail-preview', [PlannerAppointmentMailController::class, 'preview'])->name('planner.book.appointments.mail.preview');
    Route::post('/planner/book/appointments/{appointment}/mail', [PlannerAppointmentMailController::class, 'send'])->name('planner.book.appointments.mail.send');
    Route::get('/planner/tracking', [PlannerTrackingController::class, 'index'])->name('planner.tracking');
    Route::post('/planner/tracking/search', [PlannerTrackingController::class, 'search'])->name('planner.tracking.search');
    Route::get('/planner/tracking/coffrac/placed/status', [PlannerTrackingController::class, 'placedCoffracSyncStatus'])->name('planner.tracking.coffrac.placed.status');
    Route::post('/planner/tracking/coffrac/placed/refresh', [PlannerTrackingController::class, 'refreshPlacedCoffracAppointments'])->name('planner.tracking.coffrac.placed.refresh');
    Route::post('/planner/tracking/events', [PlannerTrackingController::class, 'events'])->name('planner.tracking.events');
    Route::patch('/planner/tracking/appointments/{appointment}/comment', [PlannerTrackingController::class, 'updateComment'])
        ->name('planner.tracking.appointments.comment');
    Route::post('/planner/tracking/appointments/{appointment}/problem', [PlannerTrackingController::class, 'markProblem'])
        ->name('planner.tracking.appointments.problem');
    Route::post('/planner/tracking/appointments/{appointment}/coffrac/refresh', [PlannerTrackingController::class, 'refreshCoffracAppointment'])
        ->name('planner.tracking.appointments.coffrac.refresh');
    Route::patch('/planner/tracking/appointments/{appointment}/details', [PlannerTrackingController::class, 'updateDetails'])
        ->name('planner.tracking.appointments.details');
    Route::patch('/planner/tracking/appointments/{appointment}/technician', [PlannerTrackingController::class, 'reassignTechnician'])
        ->name('planner.tracking.appointments.technician');

    Route::get('/tech/planning', [TechPlanningController::class, 'index'])->name('tech.planning');
    Route::post('/tech/planning/events', [TechPlanningController::class, 'events'])->name('tech.planning.events');
    Route::post('/tech/planning/appointments/{appointment}/coffrac/refresh', [TechPlanningController::class, 'refreshCoffracAppointment'])
        ->name('tech.planning.appointments.coffrac.refresh');

    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
