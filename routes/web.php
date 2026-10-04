<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DeployController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// ─── Auth ─────────────────────────────────────────────────────────────────────
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.post');
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// ─── Health check (public, rate-limited) ──────────────────────────────────────
Route::get('/health', [DeployController::class, 'health'])
    ->middleware('throttle:30,1')
    ->name('health');

// ─── App (authenticated) ──────────────────────────────────────────────────────
Route::middleware('auth')->group(function () {
    Route::get('/', [DeployController::class, 'dashboard'])->name('dashboard');

    // Servers
    Route::get('/servers', [ServerController::class, 'index'])->name('servers.index');
    Route::get('/servers/create', [ServerController::class, 'create'])->name('servers.create');
    Route::post('/servers', [ServerController::class, 'store'])->name('servers.store');
    Route::get('/servers/{server}', [ServerController::class, 'show'])->name('servers.show');
    Route::get('/servers/{server}/edit', [ServerController::class, 'edit'])->name('servers.edit');
    Route::put('/servers/{server}', [ServerController::class, 'update'])->name('servers.update');
    Route::delete('/servers/{server}', [ServerController::class, 'destroy'])->name('servers.destroy');
    Route::get('/servers/{server}/test-connection', [ServerController::class, 'testConnection'])->name('servers.test');

    // Sites (applications on a server)
    Route::get('/servers/{server}/sites/create', [SiteController::class, 'create'])->name('sites.create');
    Route::post('/servers/{server}/sites', [SiteController::class, 'store'])->name('sites.store');
    Route::get('/sites/{site}/edit', [SiteController::class, 'edit'])->name('sites.edit');
    Route::put('/sites/{site}', [SiteController::class, 'update'])->name('sites.update');
    Route::delete('/sites/{site}', [SiteController::class, 'destroy'])->name('sites.destroy');

    // Deployments
    Route::post('/deploy', [DeployController::class, 'trigger'])->name('deploy.trigger');
    Route::get('/deployments', [DeployController::class, 'index'])->name('deployments.index');
    Route::get('/deployments/{deployment}', [DeployController::class, 'show'])->name('deployments.show');
    Route::get('/deployments/{deployment}/poll', [DeployController::class, 'poll'])->name('deployments.poll');
    Route::get('/deployments/{deployment}/logs', [LogController::class, 'forDeployment'])->name('deployments.logs');

    // Logs
    Route::get('/logs', [LogController::class, 'index'])->name('logs.index');

    // Users (admin-only — enforced in the controller via requireAdmin())
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
});
