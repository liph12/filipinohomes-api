<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Http\Resources\UserResourceCollection;
use App\Http\Resources\UserResource;

class UserController extends Controller
{
    
    /*
    
    GET, POST, PUT

    GET - index()

    GET - show($id)

    POST - store()

    PUT - update($id)

    */

    public function index()
    {
        $users = User::get();

        return new UserResourceCollection($users);
    }

    public function show($id)
    {
        $user = User::find($id);

        return new UserResource($user);
    }

    public function store(Request $request)
    {
        // store user implementation
    }

    public function update($id, Request $request)
    {
        // to do
    }
}
