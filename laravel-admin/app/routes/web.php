<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DialplanController;
use App\Http\Controllers\PjsipController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\TenantInventoryController;
use App\Http\Controllers\TenantMemberController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:20,1')->name('login.store');
});
Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::resource('tenants', TenantController::class)->only(['index', 'create', 'store', 'show', 'update']);
    Route::post('/tenants/{tenant}/members', [TenantMemberController::class, 'store'])->name('tenants.members.store');
    Route::delete('/tenants/{tenant}/members/{user}', [TenantMemberController::class, 'destroy'])->name('tenants.members.destroy');
    Route::get('/tenants/{tenant}/inventory/{resource}', TenantInventoryController::class)->whereIn('resource', ['endpoints', 'auths', 'aors'])->name('tenants.inventory');
    Route::prefix('/tenants/{tenant}/pjsip/{resource}')->whereIn('resource', ['endpoints', 'auths', 'aors'])->group(function (): void {
        Route::get('/create', [PjsipController::class, 'create'])->name('pjsip.create');
        Route::post('/', [PjsipController::class, 'store'])->name('pjsip.store');
        Route::get('/{record}/edit', [PjsipController::class, 'edit'])->name('pjsip.edit');
        Route::patch('/{record}', [PjsipController::class, 'update'])->name('pjsip.update');
        Route::get('/{record}/delete', [PjsipController::class, 'delete'])->name('pjsip.delete');
        Route::delete('/{record}', [PjsipController::class, 'destroy'])->name('pjsip.destroy');
    });
    Route::prefix('/tenants/{tenant}/dialplan')->group(function (): void {
        Route::get('/', [DialplanController::class, 'index'])->name('dialplan.index');
        Route::get('/create', [DialplanController::class, 'create'])->name('dialplan.create');
        Route::post('/', [DialplanController::class, 'submit'])->name('dialplan.store');
        Route::post('/provision', [DialplanController::class, 'provision'])->name('dialplan.provision');
        Route::get('/{extension}/edit', [DialplanController::class, 'edit'])->whereNumber('extension')->name('dialplan.edit');
        Route::post('/{extension}', [DialplanController::class, 'submit'])->whereNumber('extension')->name('dialplan.update');
        Route::get('/{extension}/delete', [DialplanController::class, 'delete'])->whereNumber('extension')->name('dialplan.delete');
        Route::delete('/{extension}', [DialplanController::class, 'destroy'])->whereNumber('extension')->name('dialplan.destroy');
    });
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
});
