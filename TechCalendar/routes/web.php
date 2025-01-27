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
    
});