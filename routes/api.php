<?php

use App\Http\Controllers\UserController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\FurnishingController;
use Illuminate\Support\Facades\Route;

Route::apiResource('users', UserController::class);
Route::post('/login', [UserController::class, 'login']);

Route::middleware('auth:sanctum')->group(function(){

    Route::apiResource('agents', AgentController::class);
    Route::get('/agent/profile', [AgentController::class, 'profile']);
    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('furnishings', FurnishingController::class);

});
