<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
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
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
});
