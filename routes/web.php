<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CargoController;
use App\Http\Controllers\FleetController;
use App\Http\Controllers\ManifestController;
use App\Http\Controllers\OperationsController as Ops;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\VoyageController;
use Illuminate\Support\Facades\Route;

// JSON API (session-authenticated, CSRF-protected via the web middleware group).
Route::prefix('api')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:30,1');

    Route::middleware('auth')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);

        Route::get('/dashboard', [Ops::class, 'dashboard'])->middleware('perm:report.view,voyage.view');
        Route::get('/reports/utilisation', [Ops::class, 'utilisation'])->middleware('perm:report.view');

        Route::get('/operators', [FleetController::class, 'operators'])->middleware('perm:fleet.view');
        Route::get('/vessels', [FleetController::class, 'vessels'])->middleware('perm:fleet.view');
        Route::get('/vessels/{vessel}', [FleetController::class, 'vessel'])->middleware('perm:fleet.view');
        Route::post('/vessels', [FleetController::class, 'storeVessel'])->middleware('perm:fleet.manage');
        Route::post('/certificates', [FleetController::class, 'storeCertificate'])->middleware('perm:fleet.manage');
        Route::post('/maintenance', [FleetController::class, 'storeMaintenance'])->middleware('perm:fleet.manage');
        Route::get('/crew', [FleetController::class, 'crew'])->middleware('perm:fleet.view,voyage.manage');
        Route::post('/crew', [FleetController::class, 'storeCrew'])->middleware('perm:crew.manage');

        Route::get('/routes', [VoyageController::class, 'routes'])->middleware('perm:voyage.view');
        Route::get('/voyages', [VoyageController::class, 'index'])->middleware('perm:voyage.view');
        Route::get('/voyages/{voyage}', [VoyageController::class, 'show'])->middleware('perm:voyage.view');
        Route::post('/voyages', [VoyageController::class, 'store'])->middleware('perm:voyage.manage');
        Route::patch('/voyages/{voyage}/status', [VoyageController::class, 'updateStatus'])->middleware('perm:voyage.manage');

        Route::get('/voyages/{voyage}/tickets', [TicketController::class, 'index'])->middleware('perm:ticket.issue,passenger.view');
        Route::post('/voyages/{voyage}/tickets', [TicketController::class, 'store'])->middleware('perm:ticket.issue');
        Route::post('/tickets/{ticket}/board', [TicketController::class, 'board'])->middleware('perm:ticket.issue');

        Route::get('/shippers', [CargoController::class, 'shippers'])->middleware('perm:cargo.accept,cargo.view');
        Route::post('/shippers', [CargoController::class, 'storeShipper'])->middleware('perm:cargo.accept');
        Route::get('/dg-classes', [CargoController::class, 'dgClasses'])->middleware('perm:cargo.accept,cargo.view');
        Route::get('/voyages/{voyage}/consignments', [CargoController::class, 'index'])->middleware('perm:cargo.accept,cargo.view');
        Route::post('/voyages/{voyage}/consignments', [CargoController::class, 'store'])->middleware('perm:cargo.accept');

        Route::get('/voyages/{voyage}/manifests', [ManifestController::class, 'index'])->middleware('perm:manifest.view');
        Route::post('/voyages/{voyage}/manifests', [ManifestController::class, 'generate'])->middleware('perm:manifest.generate');
        Route::get('/manifests/{manifest}', [ManifestController::class, 'show'])->middleware('perm:manifest.view');
        Route::post('/manifests/verify', [ManifestController::class, 'verify'])->middleware('perm:manifest.verify');

        Route::get('/emergency/{ref}', [Ops::class, 'emergency'])->middleware('perm:emergency.view');
        Route::post('/voyages/{voyage}/incidents', [Ops::class, 'reportIncident'])->middleware('perm:incident.report');

        Route::get('/border-declarations', [Ops::class, 'declarations'])->middleware('perm:border.request,border.approve,border.view');
        Route::post('/voyages/{voyage}/border-declarations', [Ops::class, 'requestDisclosure'])->middleware('perm:border.request');
        Route::post('/border-declarations/{declaration}/decision', [Ops::class, 'approveDisclosure'])->middleware('perm:border.approve');
        Route::get('/border-declarations/{declaration}/export', [Ops::class, 'exportDisclosure'])->middleware('perm:border.view');

        Route::get('/audit', [Ops::class, 'audit'])->middleware('perm:audit.view');
        Route::get('/audit/verify', [Ops::class, 'auditVerify'])->middleware('perm:audit.view');
    });
});

Route::get('/{any?}', fn () => view('app'))->where('any', '^(?!api).*$');
