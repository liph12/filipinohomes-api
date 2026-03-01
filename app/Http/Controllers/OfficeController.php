<?php

namespace App\Http\Controllers;

use App\Models\Office;

class OfficeController extends Controller
{
    public function index()
    {
        return response()->json(
            Office::latest()->get()
        );
    }
}