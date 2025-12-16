<?php

use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::apiResource('users', UserController::class); /* /users */

/*

URL: /api/users
METHOD: POST
DATA: {

}

URL: /api/users
METHOD: GET

URL: /api/users/{id}
METHOD: GET

URL: /api/users/{32323}
METHOD: PUT
DATA : {


}

*/

Route::middleware('auth:sanctum')->group(function(){

    // // authenticated users only
    // Route::get('/testing', function(){
    //     return response()->json(['message' => 'API WORKS!']);
    // });

});


