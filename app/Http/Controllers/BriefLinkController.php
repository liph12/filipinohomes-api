<?php

namespace App\Http\Controllers;

use App\Http\Resources\ClientBriefResource;
use App\Mail\BriefReceivedMailer;
use App\Models\Agent;
use App\Models\BriefLink;
use App\Models\ClientBrief;
use App\Models\User;
use App\Support\Briefs;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Buyers & Sellers Link. Every agent, and every admin, has one permanent link
 * with two public pages on the site (/buy-with/{slug}, /sell-with/{slug}); a
 * client answers a four-step brief there and it lands on the owner's
 * dashboard page and in their inbox.
 *
 *   GET  /briefs/questions            the brief definitions (public, no token)
 *   GET  /brief-links/{slug}          the link and its owner (public, no token)
 *   POST /brief-links/{code}/briefs   a client's brief (guest token, throttled)
 *   POST /agent/brief-link            the signed-in user's link, created on first use
 *   GET  /agent/briefs                the signed-in user's own briefs
 *   GET  /admin/briefs                everyone's briefs with their owner, admins only
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
        $link = BriefLink::where('slug', $slug)->with(['agent.user', 'user.role'])->first();
        $face = $link ? Briefs::ownerFace($link->agent, $link->user) : null;
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
     * stored under the link's owner, never one named in the request.
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

        $link = BriefLink::where('code', $code)->with(['agent.user', 'user.role'])->first();
        if (! $link || ! $link->is_active || ! Briefs::ownerFace($link->agent, $link->user)) {
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
            'user_id' => $link->user_id ?? $link->agent?->user_id,
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

        $this->notifyOwner($brief, $link);

        return response()->json(['ok' => true, 'id' => $brief->id], 201);
    }

    /**
     * The signed-in user's link, created with its address the first time.
     * Agents and admins have one; an agent's link made before links had
     * owners is claimed for its agent's account here.
     */
    public function myLink(Request $request): JsonResponse
    {
        $user = $request->user();
        $agent = $this->agentOf($request);
        if (! Briefs::canOwnLink($user, $agent)) {
            return response()->json(['message' => 'Only agents and admins have a Buyers & Sellers Link.'], 403);
        }

        $link = $this->linkOf($user, $agent);
        if (! $link) {
            try {
                $link = BriefLink::create([
                    'user_id' => $user->id,
                    'agent_id' => $agent?->id,
                    'slug' => Briefs::slugFor($agent, $user),
                    'code' => Briefs::mintCode(),
                ])->refresh(); // is_active comes from the table's default
            } catch (UniqueConstraintViolationException) {
                // A second tab created it a moment ago.
                $link = $this->linkOf($user, $agent) ?? throw new \RuntimeException('Brief link vanished');
            }
        } elseif ($link->user_id === null) {
            $link->update(['user_id' => $user->id]);
            ClientBrief::where('brief_link_id', $link->id)->whereNull('user_id')->update(['user_id' => $user->id]);
        }

        return response()->json(['data' => [
            'id' => $link->id,
            'slug' => $link->slug,
            'code' => $link->code,
            'is_active' => $link->is_active,
            'created_at' => $link->created_at,
        ]]);
    }

    /** The signed-in user's own briefs, newest first; `kind` narrows to buyers or sellers. */
    public function myBriefs(Request $request)
    {
        $user = $request->user();
        $agent = $this->agentOf($request);
        if (! Briefs::canOwnLink($user, $agent)) {
            return response()->json(['message' => 'Only agents and admins have a Buyers & Sellers Link.'], 403);
        }

        $rows = ClientBrief::where(function ($q) use ($user, $agent) {
            $q->where('user_id', $user->id);
            if ($agent) {
                $q->orWhere('agent_id', $agent->id);
            }
        })
            ->when($this->kindOf($request), fn ($q, $kind) => $q->where('kind', $kind))
            ->latest('id')
            ->limit(1000)
            ->get();

        return ClientBriefResource::collection($rows);
    }

    /** Everyone's briefs with the owner each came through, admins only. */
    public function adminBriefs(Request $request)
    {
        if ($request->user()->role?->name !== 'admin') {
            return response()->json(['message' => 'Admins only.'], 403);
        }

        $rows = ClientBrief::with(['agent:id,first_name,last_name,user_id', 'user:id,name,role_id', 'user.role:id,name'])
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

    /** The user's link: theirs by account, else the one made for their agent row before links had owners. */
    private function linkOf(User $user, ?Agent $agent): ?BriefLink
    {
        return BriefLink::where('user_id', $user->id)->first()
            ?? ($agent ? BriefLink::where('agent_id', $agent->id)->first() : null);
    }

    private function kindOf(Request $request): ?string
    {
        $kind = (string) $request->query('kind', '');

        return in_array($kind, ClientBrief::KINDS, true) ? $kind : null;
    }

    /** One email per brief to the link's owner. Delivery failures are logged, never shown to the client. */
    private function notifyOwner(ClientBrief $brief, BriefLink $link): void
    {
        $to = $link->user?->email ?: $link->agent?->user?->email ?: $link->agent?->lr_email;
        if (! $to) {
            return;
        }
        try {
            Mail::to($to)->send(new BriefReceivedMailer($brief, $link));
        } catch (\Throwable $e) {
            Log::warning('Brief email failed', ['brief' => $brief->id, 'error' => $e->getMessage()]);
        }
    }
}
