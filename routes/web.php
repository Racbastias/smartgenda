<?php

use App\Http\Controllers\AgendaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CalendarAccountController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\EnsureAdmin;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/', [AgendaController::class, 'index'])->name('agenda');
    Route::get('/agenda/eventos', [AgendaController::class, 'events'])->name('agenda.events');
    Route::post('/agenda/eventos', [AgendaController::class, 'store'])->name('agenda.store');

    Route::get('/cuentas', [CalendarAccountController::class, 'index'])->name('accounts.index');
    Route::post('/cuentas', [CalendarAccountController::class, 'store'])->name('accounts.store');
    Route::post('/cuentas/google', [CalendarAccountController::class, 'googleRedirect'])->name('accounts.google');
    Route::get('/cuentas/google/callback', [CalendarAccountController::class, 'googleCallback'])->name('accounts.google.callback');
    Route::get('/cuentas/{account}/calendario', [CalendarAccountController::class, 'choose'])->name('accounts.choose');
    Route::patch('/cuentas/{account}', [CalendarAccountController::class, 'update'])->name('accounts.update');
    Route::post('/cuentas/{account}/sincronizar', [CalendarAccountController::class, 'sync'])->name('accounts.sync');
    Route::delete('/cuentas/{account}', [CalendarAccountController::class, 'destroy'])->name('accounts.destroy');

    Route::middleware(EnsureAdmin::class)->group(function () {
        Route::get('/usuarios', [UserController::class, 'index'])->name('users.index');
        Route::post('/usuarios', [UserController::class, 'store'])->name('users.store');
        Route::delete('/usuarios/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });
});
