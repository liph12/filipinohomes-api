<?php

namespace App\Http\Controllers;

use App\Http\Resources\ClientBriefResource;
use App\Mail\BriefReceivedMailer;
use App\Models\Agent;
use App\Models\BriefLink;
use App\Models\ClientBrief;
use App\Support\Briefs;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Buyers & Sellers Link. Every agent has one permanent link with two public
 * pages on the site (/buy-with/{slug}, /sell-with/{slug}); a client answers a
 * four-step brief there and it lands on the agent's dashboard page and in
 * their inbox.
 *
 *   GET  /briefs/questions            the brief definitions (public, no token)
 *   GET  /brief-links/{slug}          the link and its agent (public, no token)
 *   POST /brief-links/{code}/briefs   a client's brief (guest token, throttled)
 *   POST /agent/brief-link            the signed-in agent's link, created on first use
 *   GET  /agent/briefs                the signed-in agent's briefs
 *   GET  /admin/briefs                every agent's briefs, admins only
 */
class BriefLinkController extends Controller
{
    public function questions(): JsonResponse
    {
        return response()->json(Briefs::definitions())
            ->header('Cache-Control', 'public, max-age=3600');
    }

    public function show(string $slug): JsonResponse
    {
        $link = BriefLink::where('slug', $slug)->with('agent.user')->first();
        $face = $link ? Briefs::agentFace($link->agent) : null;
        if (! $link || ! $face) {
            return response()->json(['message' => 'This link is not available.'], 404);
        }

        return response()->json(['data' => [
            'code' => $link->code,
            'slug' => $link->slug,
            'is_active' => $link->is_active,
            'agent' => $face,
        ]]);
    }

    /**
     * A client's brief. Public by design: validated, honeypot-guarded,
     * per-IP throttled (routes), narrowed to the known option keys, and
     * stored under the link's agent, never one named in the request.
     */
    public function store(Request $request, string $code): JsonResponse
    {
        $data = $request->validate([
            'kind' => 'required|in:buyer,seller',
            'name' => 'required|string|max:200',
            'phone_code' => ['required', 'string', 'regex:/^\+\d{1,4}$/'],
            'phone' => ['required', 'string', 'regex:/^[0-9 ()-]{6,20}$/'],
            'email' => 'required|email|max:320',
            'contact_time' => 'nullable|string|in:morning,afternoon,evening',
            'budget' => 'nullable|string|max:20',
            'message' => 'nullable|string|max:2000',
            'profile' => 'nullable|array',
            'website' => 'nullable|string', // honeypot: people never see it, bots fill it
        ], [
            'phone.regex' => 'Please enter a valid mobile number.',
            'phone_code.regex' => 'Pick a valid country code.',
        ]);

        if (! empty($data['website'])) {
            // Pretend it worked and keep nothing.
            return response()->json(['ok' => true]);
        }

        $link = BriefLink::where('code', $code)->with('agent.user')->first();
        if (! $link || ! $link->is_active || ! Briefs::agentFace($link->agent)) {
            return response()->json(['message' => 'This link is no longer active.'], 404);
        }

        $kind = $data['kind'];
        $profile = Briefs::parseProfile($kind, $data['profile'] ?? null);
        foreach (config("briefs.$kind.required") as $key) {
            if (empty($profile[$key])) {
                return response()->json(['message' => 'Please answer "'.config("briefs.$kind.questions.$key.label").'".'], 422);
            }
        }

        $budget = null;
        if ($kind === 'buyer') {
            $budget = (string) ($data['budget'] ?? '');
            if (! in_array($budget, array_column(config('briefs.budget'), 0), true)) {
                return response()->json(['message' => 'Please choose your budget.'], 422);
            }
        } elseif (empty($profile['area']) && empty($profile['city'])) {
            return response()->json(['message' => 'Please tell us where the property is.'], 422);
        }

        $brief = ClientBrief::create([
            'brief_link_id' => $link->id,
            'agent_id' => $link->agent_id,
            'kind' => $kind,
            'name' => trim($data['name']),
            'phone_code' => $data['phone_code'],
            'phone' => trim($data['phone']),
            'email' => mb_strtolower(trim($data['email'])),
            'contact_time' => $data['contact_time'] ?? null,
            'budget' => $budget,
            'message' => isset($data['message']) && trim($data['message']) !== '' ? trim($data['message']) : null,
            'profile' => $profile,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
        ]);

        $this->notifyAgent($brief, $link);

        return response()->json(['ok' => true, 'id' => $brief->id], 201);
    }

    /** The signed-in agent's link, created with its address the first time. */
    public function myLink(Request $request): JsonResponse
    {
        $agent = $this->agentOf($request);
        if (! $agent) {
            return response()->json(['message' => 'Only agents have a Buyers & Sellers Link.'], 403);
        }

        $link = BriefLink::where('agent_id', $agent->id)->first();
        if (! $link) {
            try {
                $link = BriefLink::create([
                    'agent_id' => $agent->id,
                    'slug' => Briefs::slugFor($agent),
                    'code' => Briefs::mintCode(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // A second tab created it a moment ago.
                $link = BriefLink::where('agent_id', $agent->id)->firstOrFail();
            }
        }

        return response()->json(['data' => [
            'id' => $link->id,
            'slug' => $link->slug,
            'code' => $link->code,
            'is_active' => $link->is_active,
            'created_at' => $link->created_at,
        ]]);
    }

    /** The signed-in agent's briefs, newest first; `kind` narrows to buyers or sellers. */
    public function myBriefs(Request $request)
    {
        $agent = $this->agentOf($request);
        if (! $agent) {
            return response()->json(['message' => 'Only agents have a Buyers & Sellers Link.'], 403);
        }

        $rows = ClientBrief::where('agent_id', $agent->id)
            ->when($this->kindOf($request), fn ($q, $kind) => $q->where('kind', $kind))
            ->latest('id')
            ->limit(1000)
            ->get();

        return ClientBriefResource::collection($rows);
    }

    /** Every agent's briefs with the agent each came through, admins only. */
    public function adminBriefs(Request $request)
    {
        if ($request->user()->role?->name !== 'admin') {
            return response()->json(['message' => 'Admins only.'], 403);
        }

        $rows = ClientBrief::with('agent:id,first_name,last_name,user_id')
            ->when($this->kindOf($request), fn ($q, $kind) => $q->where('kind', $kind))
            ->latest('id')
            ->limit(2000)
            ->get();

        return ClientBriefResource::collection($rows);
    }

    private function agentOf(Request $request): ?Agent
    {
        return Agent::where('user_id', $request->user()->id)->first();
    }

    private function kindOf(Request $request): ?string
    {
        $kind = (string) $request->query('kind', '');

        return in_array($kind, ClientBrief::KINDS, true) ? $kind : null;
    }

    /** One email per brief. Delivery failures are logged, never shown to the client. */
    private function notifyAgent(ClientBrief $brief, BriefLink $link): void
    {
        $agent = $link->agent;
        $to = $agent?->user?->email ?: $agent?->lr_email;
        if (! $to) {
            return;
        }
        try {
            Mail::to($to)->send(new BriefReceivedMailer($brief, $agent));
        } catch (\Throwable $e) {
            Log::warning('Brief email failed', ['brief' => $brief->id, 'error' => $e->getMessage()]);
        }
    }
}
