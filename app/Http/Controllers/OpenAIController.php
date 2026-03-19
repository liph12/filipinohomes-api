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

    public function propertyInquiry(Request $request)
    {
        $msg = $request->messages;
        $thread = implode(".", $msg);
        $classification = $this->sqService->classifyMessage($thread);
        $isNormal = $classification === "normal";

        return [
            'message' => $isNormal ? $this->sqService->replyNormal($thread) : "Thank you for your inquiry! We will get back to you shortly with more details about the property listings that match your preferences.",
            // 'classification' => $this->sqService->classifyMessage($thread),
        ];
    }
}
