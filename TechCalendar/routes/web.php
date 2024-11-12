<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;

Route::get('/', [AuthController::class, 'showLoginForm'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.submit');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Routes protégées par l'authentification
Route::middleware(['auth'])->group(function () {
    // Routes pour l'administrateur
    Route::get('/admin', function () {
        $role = 'administrateur';
        return view('admin.dashboard', compact('role'));
    })->name('panel.admin');

    Route::get('/admin/user-manager', function () {
        return view('admin.userManager');
    })->name('user.manager');

    // Routes pour l'assistante
    Route::get('/assistant', function () {
        $role = 'assistante';
        return view('assist.dashboard', compact('role'));
    })->name('panel.assistant');

    Route::get('/assistant/rdv', function () {
        return view('assist.rdv');
    })->name('assistant.rdv');

    // Routes pour le technicien
    Route::get('/technician', function () {
        $role = 'technicien';
        return view('tech.dashboard', compact('role'));
    })->name('panel.technician');

    Route::get('/technician/calendar', function () {
        return view('tech.calendar');
    })->name('technician.calendar');

    Route::get('/technician/profile', function () {
        return view('tech.profile');
    })->name('technician.profile');
});