<?php

use App\Http\Controllers\Crm\ClientManagementController;
use App\Http\Controllers\Crm\ClientPortalController;
use App\Http\Middleware\EnsureClientPortal;
use App\Http\Middleware\EnsureCrmV2Enabled;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', EnsureCrmV2Enabled::class])->group(function (): void {
    Route::prefix('manager/clients')->name('manager.clients.')->controller(ClientManagementController::class)->group(function (): void {
        Route::get('/create', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('/{client}/edit', 'edit')->name('edit');
        Route::put('/{client}', 'update')->name('update');
        Route::post('/{client}/accounts', 'storeAccount')->name('accounts.store');
        Route::put('/{client}/accounts/{account}', 'updateAccount')->name('accounts.update');
    });
    Route::prefix('espace-client')->name('client.')->middleware(EnsureClientPortal::class)->controller(ClientPortalController::class)->group(function (): void {
        Route::get('/', 'index')->name('dossiers');
        Route::get('/dossiers/{dossier}', 'show')->name('dossiers.show');
        Route::post('/dossiers/{dossier}/comments', 'comment')->middleware('throttle:30,1')->name('dossiers.comments');
        Route::post('/dossiers/{dossier}/documents', 'upload')->middleware('throttle:15,1')->name('dossiers.upload');
        Route::post('/dossiers/{dossier}/prestations/{operation}/recontrole', 'respondToNonConformity')
            ->middleware('throttle:15,1')->name('dossiers.recontrol');
        Route::get('/documents/{document}', 'download')->name('documents.download');
        Route::get('/dossiers/{dossier}/archive/{document}', 'archive')->whereNumber('document')->name('dossiers.archive');
        Route::get('/dossiers/{dossier}/documents-lots/{document}', 'lotDocument')->whereNumber('document')->name('dossiers.lot-documents.download');
        Route::get('/lots', 'lots')->name('lots');
        Route::get('/lots/{lot}', 'lot')->whereNumber('lot')->name('lots.show');
    });
});
