<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\VendorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public API Routes
|--------------------------------------------------------------------------
*/

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

/*
|--------------------------------------------------------------------------
| Protected API Routes (Sanctum Token Required)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/users', [AuthController::class, 'getUsers']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Profile & Bank Details Routes
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::put('/profile/password', [ProfileController::class, 'updatePassword']);

    // Vendors & Items REST API Routes
    Route::apiResource('vendors', VendorController::class);
    Route::apiResource('items', ItemController::class);
    Route::apiResource('bills', BillController::class);
});
