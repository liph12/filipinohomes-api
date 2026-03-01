<?php
namespace App\Http\Controllers;
use App\Http\Resources\PropertyTypeResourceCollection;
use App\Http\Resources\PropertyTypeResource;
use App\Models\PropertyType;
use Illuminate\Http\Request;

class PropertyTypeController extends Controller
{
     public function index()
    {
        return new PropertyTypeResourceCollection(
           PropertyType::get()
        );
    }
}
