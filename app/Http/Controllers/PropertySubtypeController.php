<?php
namespace App\Http\Controllers;
use App\Http\Resources\PropertySubtypeResourceCollection;
use App\Http\Resources\PropertySubtypeResource;
use App\Models\PropertySubtype;
use Illuminate\Http\Request;

class PropertySubtypeController extends Controller
{
     public function index()
    {
        return new PropertySubtypeResourceCollection(
           PropertySubtype::get()
        );
    }
}
