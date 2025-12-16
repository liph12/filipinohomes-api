<?php

use App\Http\Controllers\UserController;
use App\Http\Controllers\AgentController;
use Illuminate\Support\Facades\Route;

Route::apiResource('users', UserController::class);
Route::post('/login', [UserController::class, 'login']);

Route::middleware('auth:sanctum')->group(function(){

    Route::apiResource('agents', AgentController::class);

});

Route::apiResource('agents', AgentController::class)->except('store');

