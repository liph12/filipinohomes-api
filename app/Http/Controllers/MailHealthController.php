<?php

namespace App\Http\Controllers;

use App\Services\MailHealthService;
use Illuminate\Http\JsonResponse;

/**
 * System Settings → Email Health: is the website's email going out, and if
 * not, why (limit reached, account blocked, wrong password, server down, a
 * bad address). Read-only; see MailHealthService. Admins only.
 *
 *   GET /admin/mail-health
 */
class MailHealthController extends Controller
{
    public function __invoke(MailHealthService $health): JsonResponse
    {
        return response()->json($health->status());
    }
}
