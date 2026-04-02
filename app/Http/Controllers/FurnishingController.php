<?php
namespace App\Http\Controllers;
use App\Http\Resources\FurnishingResourceCollection;
use App\Models\Furnishing;
class FurnishingController extends Controller
{
    public function index()
    {
        return new FurnishingResourceCollection(
            Furnishing::get()
        );
    }
}
