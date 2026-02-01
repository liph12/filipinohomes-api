<?php
use App\Http\Controllers\{
    UserController,
    AgentController,
    CategoryController,
    PropertyTypeController,
    FurnishingController,
    PropertyAttributesController,
    PropertyController,
    ListingController,
    ListingConversationController,
    ListingInquiryController,
    FullListingController,
    PropertySubtypeController,
    ProjectController,
    AmenityController,
    ImageUploadController
};
use Illuminate\Support\Facades\Route;

Route::apiResource('users', UserController::class);
Route::post('/login', [UserController::class, 'login']);
Route::middleware('auth:sanctum')->group(function(){
    
    Route::apiResource('agents', AgentController::class);
    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('property_types', PropertyTypeController::class);
    Route::apiResource('property_subtypes', PropertySubtypeController::class);
    Route::apiResource('furnishings', FurnishingController::class);
    Route::apiResource('property_attributes', PropertyAttributesController::class);
    Route::apiResource('properties', PropertyController::class);
    Route::apiResource('listings', ListingController::class);
    Route::apiResource('listing_conversations', ListingConversationController::class);
    Route::apiResource('listing_inquiries', ListingInquiryController::class);
    Route::apiResource('amenities', AmenityController::class);
    Route::post('/logout', [UserController::class, 'logout']);
    Route::post('/full-listing', [FullListingController::class, 'store']);
    Route::post('/upload', [ImageUploadController::class, 'upload']);
    Route::get('/agent/profile', [AgentController::class, 'profile']);
    Route::get('/projects', [ProjectController::class, 'index']);


});
