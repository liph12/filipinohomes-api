<?php

namespace App\Http\Controllers;

use App\Mail\ReactivationApprovedMailer;
use App\Models\Agent;
use App\Models\ReactivationRequest;
use App\Services\Agent\AgentStatusEffects;
use App\Services\AuditMailService;
use App\Support\AvatarUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * A deactivated agent asks to be switched back on; an admin accepts or declines.
 *
 * The two agent routes sit in EnsureAgentActive::ALLOWED, because the agent
 * asking is by definition blocked. The admin routes are behind the admin role.
 */
class ReactivationRequestController extends Controller
{
    /** Relations the admin payload reads (user columns kept narrow; avatar is the photo fallback). */
    private const WITH = ['agent', 'user:id,name,email,avatar,last_online_at', 'reviewer:id,name'];

    /** The agent's most recent request, so the blocked modal can show where it stands. */
    public function mine(Request $request): JsonResponse
    {
        $latest = ReactivationRequest::where('user_id', $request->user()->id)
            ->latest('id')
            ->first();

        return response()->json(['data' => $latest ? $this->agentPayload($latest) : null]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:10|max:2000',
        ]);

        $user = $request->user();
        $agent = $user->agent;

        // Only the 45-day dormancy lockout is requestable. Terminated / resigned
        // / inactive accounts are handled by an admin directly.
        if (! $agent || $agent->status !== 'deactivated') {
            return response()->json([
                'message' => 'Only deactivated accounts can request reactivation.',
            ], 422);
        }

        // Lock the agent row so two quick submits can't both pass the check.
        $created = DB::transaction(function () use ($agent, $user, $validated) {
            Agent::whereKey($agent->id)->lockForUpdate()->first();

            if (ReactivationRequest::where('agent_id', $agent->id)->where('status', ReactivationRequest::PENDING)->exists()) {
                return null;
            }

            return ReactivationRequest::create([
                'agent_id' => $agent->id,
                'user_id' => $user->id,
                'reason' => trim($validated['reason']),
                'status' => ReactivationRequest::PENDING,
            ]);
        });

        if ($created === null) {
            return response()->json([
                'message' => 'You already have a reactivation request waiting for review.',
            ], 422);
        }

        return response()->json(['data' => $this->agentPayload($created)], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', ReactivationRequest::PENDING);

        $requests = ReactivationRequest::with(self::WITH)
            ->when(
                in_array($status, [ReactivationRequest::PENDING, ReactivationRequest::APPROVED, ReactivationRequest::DECLINED], true),
                fn ($q) => $q->where('status', $status),
            )
            // Oldest pending first so nobody waits behind newer requests; the
            // handled lists read newest first.
            ->orderBy('created_at', $status === ReactivationRequest::PENDING ? 'asc' : 'desc')
            ->paginate(20)
            ->through(fn (ReactivationRequest $r) => $this->adminPayload($r));

        return response()->json($requests);
    }

    public function pendingCount(): JsonResponse
    {
        return response()->json([
            'count' => ReactivationRequest::where('status', ReactivationRequest::PENDING)->count(),
        ]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $reviewer = $request->user();
        $result = DB::transaction(function () use ($id, $reviewer) {
            $req = ReactivationRequest::lockForUpdate()->findOrFail($id);
            if ($req->status !== ReactivationRequest::PENDING) {
                return ['error' => 'This request was already handled.'];
            }

            $agent = Agent::withTrashed()->with('user')->find($req->agent_id);
            if (! $agent || $agent->trashed() || $agent->status !== 'deactivated') {
                return ['error' => "This agent is no longer deactivated, so there is nothing to reactivate. Decline the request to clear it."];
            }

            $agent->auditDescription = "Status: deactivated → active (reactivation request #{$req->id})";
            $agent->auditSource = 'reactivation_approval';
            $agent->update(['status' => 'active']);

            // Restart the 45-day clock, otherwise agents:deactivate-dormant puts
            // the account straight back to deactivated before they next sign in.
            $agent->user?->recordLoginActivity();

            $req->update([
                'status' => ReactivationRequest::APPROVED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);

            return ['request' => $req, 'agent' => $agent];
        });

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 422);
        }

        /** @var Agent $agent */
        $agent = $result['agent'];

        app(AgentStatusEffects::class)->afterChange($agent);
        $this->sendApprovedEmail($agent);

        return response()->json(['data' => $this->adminPayload($result['request']->fresh(self::WITH))]);
    }

    public function decline(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'admin_note' => 'nullable|string|max:1000',
        ]);

        $req = DB::transaction(function () use ($id, $request, $validated) {
            $req = ReactivationRequest::lockForUpdate()->findOrFail($id);
            if ($req->status !== ReactivationRequest::PENDING) {
                return null;
            }

            $req->update([
                'status' => ReactivationRequest::DECLINED,
                'admin_note' => isset($validated['admin_note']) ? trim($validated['admin_note']) ?: null : null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            return $req;
        });

        if ($req === null) {
            return response()->json(['message' => 'This request was already handled.'], 422);
        }

        return response()->json(['data' => $this->adminPayload($req->fresh(self::WITH))]);
    }

    private function sendApprovedEmail(Agent $agent): void
    {
        $email = $agent->user?->email;
        if (! $email) {
            return;
        }

        $loginUrl = rtrim((string) env('FRONTEND_URL', 'https://filipinohomes.com'), '/').'/login';
        $name = trim($agent->first_name.' '.$agent->last_name) ?: ($agent->user->name ?? 'Agent');

        // The account is already reactivated; a mail failure must not undo or
        // fail the approval. It is logged and audited for follow-up.
        try {
            Mail::to($email)->send(new ReactivationApprovedMailer($name, $loginUrl));
        } catch (\Throwable $e) {
            Log::warning('Reactivation approved email failed', ['agent_id' => $agent->id, 'error' => $e->getMessage()]);
            app(AuditMailService::class)->recordFailure(
                $e,
                ReactivationApprovedMailer::class,
                [$email],
                'Your Filipino Homes account is active again',
            );
        }
    }

    /** The agent's own photo (an array cast), else the user's, as one clean URL string. */
    private function avatarFor(?Agent $agent, mixed $user): ?string
    {
        $own = is_array($agent?->avatar) ? $agent->avatar : [$agent?->avatar];
        foreach ([...$own, $user?->avatar] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return AvatarUrl::clean($candidate);
            }
        }

        return null;
    }

    private function agentPayload(ReactivationRequest $r): array
    {
        return [
            'id' => $r->id,
            'status' => $r->status,
            'reason' => $r->reason,
            'admin_note' => $r->admin_note,
            'created_at' => $r->created_at,
            'reviewed_at' => $r->reviewed_at,
        ];
    }

    private function adminPayload(ReactivationRequest $r): array
    {
        $agent = $r->agent;

        return [
            'id' => $r->id,
            'status' => $r->status,
            'reason' => $r->reason,
            'admin_note' => $r->admin_note,
            'created_at' => $r->created_at,
            'reviewed_at' => $r->reviewed_at,
            'reviewed_by' => $r->reviewer?->name,
            'agent' => [
                'id' => $agent?->id,
                'name' => $agent ? trim($agent->first_name.' '.$agent->last_name) : null,
                'avatar' => $this->avatarFor($agent, $r->user),
                'status' => $agent?->status,
                'email' => $r->user?->email,
                'last_online_at' => $r->user?->last_online_at,
            ],
        ];
    }
}
