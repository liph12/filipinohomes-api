<?php
use App\Http\Controllers\{
    UserController,
    AgentController,
    CategoryController,
    FurnishingController,
    PropertyAttributesController,
    PropertyController,
    ListingController,
    ListingConversationController,
    ListingInquiryController
};
use Illuminate\Support\Facades\Route;

Route::apiResource('users', UserController::class);
Route::post('/login', [UserController::class, 'login']);

Route::middleware('auth:sanctum')->group(function(){

    Route::apiResource('agents', AgentController::class);
    Route::get('/agent/profile', [AgentController::class, 'profile']);
    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('furnishings', FurnishingController::class);
    Route::apiResource('property_attributes', PropertyAttributesController::class);
    Route::apiResource('properties', PropertyController::class);
    Route::apiResource('listings', ListingController::class);
    Route::apiResource('listing_conversations', ListingConversationController::class);
    Route::apiResource('listing_inquiries', ListingInquiryController::class);

});
