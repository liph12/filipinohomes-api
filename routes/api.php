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
    ImageUploadController,
    BlogCategoryController,
    PostController,
    OfficeController
};
use Illuminate\Support\Facades\Route;

Route::post('/login', [UserController::class, 'login']);
Route::get('/blogs', [PostController::class, 'index']); 
Route::get('/blog-categories', [BlogCategoryController::class, 'index']);
Route::get('/blogs/{slug}', [BlogCategoryController::class, 'show']);
Route::get('/posts/{slug}', [PostController::class, 'show']);
Route::get('/offices', [OfficeController::class, 'index']);
Route::get('/listings', [ListingController::class, 'index']); 
Route::get('/listings/subtype-counts', [ListingController::class, 'subtypeCounts']);
Route::get('/listings/featured', [ListingController::class, 'featured']);
Route::get('/listings/{slug}', [ListingController::class, 'show']);
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/property_types', [PropertyTypeController::class, 'index']);
Route::get('/property_subtypes', [PropertySubtypeController::class, 'index']);
Route::get('/furnishings', [FurnishingController::class, 'index']);
Route::get('/amenities', [AmenityController::class, 'index']);

Route::middleware('auth:sanctum')->group(function(){
    
    Route::apiResource('users', UserController::class);
    Route::apiResource('agents', AgentController::class);
    Route::apiResource('property_attributes', PropertyAttributesController::class);
    Route::apiResource('properties', PropertyController::class);
    Route::apiResource('listing-inquiries', ListingInquiryController::class);
    Route::apiResource('listings', ListingController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('listing-conversations', ListingConversationController::class)->only(['index', 'store']);
    Route::apiResource('full-listing', FullListingController::class)->only(['store', 'update', 'destroy'])->parameters(['full-listing' => 'listing']);
    Route::patch('/listings/{listing}/visibility', [ListingController::class, 'updateVisibility']);
    Route::patch('/listings/{listing}/status', [ListingController::class, 'updateStatus']);
    Route::get('/my-listings', [ListingController::class, 'myListings']);
    Route::get('/user/profile', [UserController::class, 'profile']);
    Route::get('/agent/profile', [AgentController::class, 'profile']);
    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/upload', [ImageUploadController::class, 'upload']);
    Route::post('/logout', [UserController::class, 'logout']);

});
