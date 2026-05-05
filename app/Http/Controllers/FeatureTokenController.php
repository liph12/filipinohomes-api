<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\FeatureToken;
use App\Models\Listing;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FeatureTokenController extends Controller
{
    // ─── Admin: issue a token to an agent ─────────────────────────────────────
    public function issue(Request $request)
    {
        $user = $request->user();
        if ($user->role?->name !== 'admin') abort(403);

        $data = $request->validate([
            'agent_id'   => 'required|exists:agents,id',
            'expires_at' => 'required|date|after:now',
        ]);

        $token = FeatureToken::create([
            'agent_id'   => $data['agent_id'],
            'created_by' => $user->id,
            'token'      => Str::upper(Str::random(12)),
            'expires_at' => $data['expires_at'],
        ]);

        return response()->json($this->formatToken($token), 201);
    }

    // ─── Admin: list all tokens for a given agent ──────────────────────────────
    public function indexForAgent(Request $request, $agentId)
    {
        $user = $request->user();
        if ($user->role?->name !== 'admin') abort(403);

        $tokens = FeatureToken::where('agent_id', $agentId)
            ->with('listing:id,name,slug')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($t) => $this->formatToken($t));

        return response()->json($tokens);
    }

    // ─── Admin: revoke (delete) an unused token ────────────────────────────────
    public function revoke(Request $request, $id)
    {
        $user = $request->user();
        if ($user->role?->name !== 'admin') abort(403);

        $token = FeatureToken::findOrFail($id);

        if ($token->used_at) {
            return response()->json(['message' => 'Token has already been used and cannot be revoked.'], 422);
        }

        $token->delete();
        return response()->json(['message' => 'Token revoked.']);
    }

    // ─── Agent: list own available (unused, not expired) tokens ───────────────
    public function myTokens(Request $request)
    {
        $agent = $request->user()->agent;
        if (!$agent) abort(403);

        $tokens = FeatureToken::where('agent_id', $agent->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->orderBy('expires_at')
            ->get()
            ->map(fn($t) => $this->formatToken($t));

        return response()->json($tokens);
    }

    // ─── Agent: apply a token to one of their listings ────────────────────────
    public function apply(Request $request, $id)
    {
        $agent = $request->user()->agent;
        if (!$agent) abort(403);

        $token = FeatureToken::where('id', $id)
            ->where('agent_id', $agent->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->firstOrFail();

        $data = $request->validate([
            'listing_id' => 'required|exists:listings,id',
        ]);

        // Ensure the listing belongs to this agent
        $listing = Listing::where('id', $data['listing_id'])
            ->where('agent_id', $agent->id)
            ->firstOrFail();

        $token->update([
            'used_at'    => now(),
            'listing_id' => $listing->id,
        ]);

        $listing->update([
            'is_featured'    => true,
            'featured_until' => $token->expires_at,
        ]);

        return response()->json([
            'message'       => 'Listing featured successfully.',
            'listing_id'    => $listing->id,
            'featured_until' => $token->expires_at->toDateTimeString(),
        ]);
    }

    // ─── Shared formatter ─────────────────────────────────────────────────────
    private function formatToken(FeatureToken $t): array
    {
        return [
            'id'             => $t->id,
            'token'          => $t->token,
            'expires_at'     => $t->expires_at?->toDateTimeString(),
            'used_at'        => $t->used_at?->toDateTimeString(),
            'listing_id'     => $t->listing_id,
            'listing_name'   => $t->listing?->name,
            'listing_slug'   => $t->listing?->slug,
            'is_used'        => $t->used_at !== null,
            'is_expired'     => $t->expires_at?->isPast() ?? false,
            'created_at'     => $t->created_at?->toDateTimeString(),
        ];
    }
}
