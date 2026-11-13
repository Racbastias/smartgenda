<?php

use App\Http\Controllers\AgendaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CalendarAccountController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TodayController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\EnsureAdmin;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::get('/primer-uso', [AuthController::class, 'setup'])->name('setup');
    Route::post('/primer-uso', [AuthController::class, 'storeSetup'])->middleware('throttle:5,1');
    Route::get('/recuperar', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/recuperar', [PasswordResetController::class, 'email'])->name('password.email')->middleware('throttle:3,1');
    Route::get('/recuperar/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/restablecer', [PasswordResetController::class, 'update'])->name('password.store')->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/', [TodayController::class, 'index'])->name('today');

    Route::get('/cuenta', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/cuenta', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/cuenta/contrasena', [ProfileController::class, 'password'])->name('profile.password')->middleware('throttle:6,1');
    Route::patch('/cuenta/avisos', [ProfileController::class, 'notifications'])->name('profile.notifications');
    Route::post('/cuenta/avisos/dispositivos', [ProfileController::class, 'subscribe'])->name('push.subscribe');
    Route::delete('/cuenta/avisos/dispositivos', [ProfileController::class, 'unsubscribe'])->name('push.unsubscribe');
    Route::post('/cuenta/avisos/prueba', [ProfileController::class, 'test'])->name('push.test')->middleware('throttle:6,1');
    Route::get('/agenda', [AgendaController::class, 'index'])->name('agenda');
    Route::get('/agenda/semana', [AgendaController::class, 'week'])->name('agenda.week');
    Route::get('/agenda/eventos', [AgendaController::class, 'events'])->name('agenda.events');
    Route::post('/agenda/eventos', [AgendaController::class, 'store'])->name('agenda.store');
    Route::delete('/agenda/eventos/{event}', [AgendaController::class, 'destroy'])->name('agenda.destroy');

    Route::get('/pendientes', [InboxController::class, 'index'])->name('inbox.index');
    Route::post('/pendientes/revisar', [InboxController::class, 'refresh'])->name('inbox.refresh')->middleware('throttle:4,1');
    Route::patch('/pendientes/{item}', [InboxController::class, 'update'])->name('inbox.update');
    Route::post('/pendientes/{item}/responder', [InboxController::class, 'reply'])->name('inbox.reply')->middleware('throttle:20,1');
    Route::post('/pendientes/{item}/papelera', [InboxController::class, 'trash'])->name('inbox.trash');
    Route::post('/pendientes/{item}/bloquear', [InboxController::class, 'block'])->name('inbox.block');
    Route::delete('/remitentes-bloqueados/{blocked}', [InboxController::class, 'unblock'])->name('inbox.unblock');

    Route::get('/cuentas', [CalendarAccountController::class, 'index'])->name('accounts.index');
    Route::post('/cuentas', [CalendarAccountController::class, 'store'])->name('accounts.store');
    Route::post('/cuentas/google', [CalendarAccountController::class, 'googleRedirect'])->name('accounts.google');
    Route::get('/cuentas/google/callback', [CalendarAccountController::class, 'googleCallback'])->name('accounts.google.callback');
    Route::get('/cuentas/{account}/calendario', [CalendarAccountController::class, 'choose'])->name('accounts.choose');
    Route::patch('/cuentas/{account}', [CalendarAccountController::class, 'update'])->name('accounts.update');
    Route::post('/cuentas/{account}/codigo', [CalendarAccountController::class, 'reauthenticate'])->name('accounts.reauth')->middleware('throttle:10,1');
    Route::post('/cuentas/{account}/sincronizar', [CalendarAccountController::class, 'sync'])->name('accounts.sync');
    Route::delete('/cuentas/{account}', [CalendarAccountController::class, 'destroy'])->name('accounts.destroy');

    Route::middleware(EnsureAdmin::class)->group(function () {
        Route::get('/usuarios', [UserController::class, 'index'])->name('users.index');
        Route::post('/usuarios', [UserController::class, 'store'])->name('users.store');
        Route::put('/usuarios/{user}/contrasena', [UserController::class, 'password'])->name('users.password');
        Route::delete('/usuarios/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });
});
