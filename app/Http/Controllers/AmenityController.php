<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Resources\AmenityResourceCollection;
use App\Http\Resources\AmenityResource;
use App\Models\Amenity;

class AmenityController extends Controller
{
    public function index()
    {
        return new AmenityResourceCollection(
           Amenity::get()
        );
    }
}
