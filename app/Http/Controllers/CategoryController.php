<?php
namespace App\Http\Controllers;
use App\Http\Resources\CategoryResourceCollection;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Request;
class CategoryController extends Controller
{
    public function index()
    {
        return new CategoryResourceCollection(
           Category::get()
        );
    }
}
