<?php

use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index']);
Route::get('/dashboard', [\App\Http\Controllers\DashboardController::class, 'index']);
Route::get('/dashboard/mtd', [\App\Http\Controllers\DashboardController::class, 'mtd']);
