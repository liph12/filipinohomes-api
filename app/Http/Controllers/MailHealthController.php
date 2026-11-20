<?php

namespace App\Http\Controllers;

use App\Services\MailHealthService;
use App\Support\MailFailover;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * System Settings → Email Health: is the website's email going out, and if
 * not, why (limit reached, account blocked, wrong password, server down, a
 * bad address) — per SMTP account; see MailHealthService. Admins only.
 *
 *   GET /admin/mail-health
 *   PUT /admin/mail-health/first   which account is tried first (MAIL_MAILER=failover)
 */
class MailHealthController extends Controller
{
    public function show(MailHealthService $health): JsonResponse
    {
        return response()->json($health->status());
    }

    public function first(Request $request, MailHealthService $health): JsonResponse
    {
        $accounts = MailFailover::configured();
        abort_if(count($accounts) < 2, 422, 'Only one email account is set up on this server (MAIL_MAILER / MAIL_FAILOVER).');

        $data = $request->validate(['mailer' => 'required|string|in:'.implode(',', $accounts)]);
        MailFailover::setFirst($data['mailer']);

        return response()->json($health->status());
    }
}
