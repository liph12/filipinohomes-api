<?php
namespace App\Http\Controllers;
use App\Http\Resources\FurnishingResourceCollection;
use App\Http\Resources\FurnishingResource;
use App\Models\Furnishing;
use Illuminate\Http\Request;
class FurnishingController extends Controller
{
    public function index()
    {
        return new FurnishingResourceCollection(
            Furnishing::get()
        );
    }
}
