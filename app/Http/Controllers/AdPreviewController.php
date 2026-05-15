<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AdPreviewController extends Controller
{
    public function generateToken(Request $request)
    {
        $token = Str::random(64); 

        Cache::put("ad_preview_token:{$token}", true, now()->addMinutes(30));

        return response()->json([
            'token' => $token,
            'expires_in' => 1800,
        ]);
    }
}
