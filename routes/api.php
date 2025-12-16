<?php

use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function(){

    // // authenticated users only
    // Route::get('/testing', function(){
    //     return response()->json(['message' => 'API WORKS!']);
    // });

});


