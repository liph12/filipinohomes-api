<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\OpenAI\InquiryService;

class OpenAIController extends Controller
{
    private $sqService;

    public function __construct(InquiryService $s)
    {
        $this->sqService = $s;
    }

    public function basicReply(Request $request)
    {
        $msg = $request->message;

        return response()->json([
            'message' => $msg,
            'classification' => $this->sqService->parsePropertyQuery($msg),
        ]);
    }

    public function extractQuery($q)
    {
        return response()->json($this->sqService->parsePropertyQuery($q));
    }
}
