<?php

namespace App\Services\OpenAI;

use App\Models\Agent;
use App\Models\Inquiry;
use App\Models\Listing;
use App\Models\ListingInquiry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * ASA-GPT's "Ask the agent" / "Book a viewing": a lead for the listing's agent WITH the conversation
 * attached, so the agent opens it already knowing the budget, the area and what the buyer was told.
 *
 * Signed-in buyers get a real listing inquiry (the agent's My Inquiries + Messages, same as the
 * listing page); guests land in the admin inquiry inbox (source "asa_gpt") and the agent is emailed
 * directly. No new tables: both channels already exist.
 */
class LeadService extends CacheService
{
    /**
     * @param  array{listing_id:int, name:string, email:?string, mobile:?string, kind:string, preferred_at:?string, note:?string}  $in
     * @return array<string, mixed>
     */
    public function create(Request $request, array $in): array
    {
        $listing = Listing::publiclyListed()->with(['property', 'agent.user'])->findOrFail($in['listing_id']);
        $agent = $listing->agent;
        $agentName = trim("{$agent->first_name} {$agent->last_name}");
        $agentEmail = $agent->user->email ?? null;
        $site = rtrim((string) config('services.youtube.site_url', 'https://filipinohomes.com'), '/');
        $title = trim((string) ($listing->property->name ?? $listing->name)) ?: $listing->name;

        $summary = $this->conversationSummary($request, (int) $listing->id);
        $kindLabel = $in['kind'] === 'viewing' ? 'Viewing request' : 'Question about the listing';
        $lines = [
            "ASA-GPT lead — {$kindLabel}",
            "Listing: {$title} (₱".number_format((float) $listing->price).") — {$site}/{$listing->slug}",
            'Buyer: '.$in['name'].($in['mobile'] ? " · {$in['mobile']}" : '').($in['email'] ? " · {$in['email']}" : ''),
        ];
        if ($in['kind'] === 'viewing' && ! empty($in['preferred_at'])) {
            $lines[] = 'Preferred schedule: '.$in['preferred_at'];
        }
        if (! empty($in['note'])) {
            $lines[] = 'Message: '.$in['note'];
        }
        if ($summary['asked'] !== []) {
            $lines[] = '';
            $lines[] = 'What they asked ASA:';
            foreach ($summary['asked'] as $q) {
                $lines[] = '  • '.$q;
            }
        }
        if ($summary['context'] !== null) {
            $lines[] = '';
            $lines[] = 'Market context ASA showed them: '.$summary['context'];
        }
        $message = implode("\n", $lines);

        $user = Auth::guard('sanctum')->user();
        $channel = 'inbox';
        if ($user !== null) {
            // The same inquiry the listing page creates: it appears in the agent's My Inquiries.
            $inquiry = ListingInquiry::firstOrCreate(
                ['listing_id' => $listing->id, 'client_id' => $user->id],
                ['agent_id' => $listing->agent_id, 'status' => 'pending', 'geo_coordinates' => null],
            );
            $inquiry->conversations()->create(['sender_id' => $user->id, 'message' => $message]);
            if ($inquiry->status === 'pending') {
                $inquiry->update(['status' => 'active']);
            }
            $channel = 'inquiry';
        } else {
            Inquiry::create([
                'name' => $in['name'],
                'email' => $in['email'] ?: 'no-email@asa-lead.filipinohomes.com',
                'message' => $message,
                'source' => 'asa_gpt',
                'device' => ['device_id' => $request->input('device_id'), 'agent' => $request->userAgent()],
            ]);
        }

        // The agent hears about it directly either way. Mail trouble must never lose the lead.
        if ($agentEmail) {
            try {
                Mail::raw($message."\n\nReply to the buyer directly. — ASA-GPT, Filipino Homes", function ($m) use ($agentEmail, $agentName, $title, $in, $kindLabel) {
                    $m->to($agentEmail, $agentName)->subject("[ASA-GPT] {$kindLabel}: {$title} — {$in['name']}");
                    if (! empty($in['email'])) {
                        $m->replyTo($in['email'], $in['name']);
                    }
                });
            } catch (\Throwable $e) {
                Log::warning('ASA lead mail failed', ['listing' => $listing->id, 'error' => $e->getMessage()]);
            }
        }

        $reply = $in['kind'] === 'viewing'
            ? "Your viewing request for **{$title}** is with **{$agentName}**. They'll reach you".($in['mobile'] ? " at {$in['mobile']}" : '').($in['email'] ? ($in['mobile'] ? ' or ' : ' at ')."{$in['email']}" : '').' to confirm a schedule.'
            : "Your question about **{$title}** is with **{$agentName}**. They'll reply".($in['mobile'] ? " at {$in['mobile']}" : '').($in['email'] ? ($in['mobile'] ? ' or ' : ' at ')."{$in['email']}" : '').'.';
        $this->appendMessages($request, [[
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'role' => 'assistant',
            'content' => $reply,
        ]]);

        return [
            'message' => $reply,
            'channel' => $channel,
            'agent' => ['name' => $agentName, 'mobile' => $agent->user->mobile_no ?? $agent->mobile_no ?? null, 'avatar' => $agent->user->avatar ?? null],
        ];
    }

    /**
     * "List it with an agent" from the valuation panel: an owner's lead for the agent who sells most
     * in their area, with ASA's estimate attached. Always the admin inquiry inbox (there is no listing
     * to hang a listing-inquiry on) plus a direct email to the agent.
     *
     * @param  array{agent_id:int, name:string, email:?string, mobile:?string, note:?string}  $in
     * @return array<string, mixed>
     */
    public function createSellerLead(Request $request, array $in): array
    {
        $agent = Agent::with('user')->findOrFail($in['agent_id']);
        $agentName = trim("{$agent->first_name} {$agent->last_name}");
        $agentEmail = $agent->user->email ?? null;

        $worth = $this->latestWorth($request);
        $lines = [
            'ASA-GPT lead — Owner wants to list a property',
            'Owner: '.$in['name'].($in['mobile'] ? " · {$in['mobile']}" : '').($in['email'] ? " · {$in['email']}" : ''),
        ];
        if ($worth !== null) {
            $lines[] = sprintf('Property: %s in %s', $worth['what'], $worth['scope_label']);
            $lines[] = sprintf('ASA\'s estimate from %d comparable listings: %s (%s – %s)%s', $worth['peers'], PriceCommandService::peso((float) $worth['estimate']), PriceCommandService::peso((float) $worth['low']), PriceCommandService::peso((float) $worth['high']), ($worth['category'] ?? '') === 'For Rent' ? ' per month' : '');
        }
        if (! empty($in['note'])) {
            $lines[] = 'Message: '.$in['note'];
        }
        $message = implode("\n", $lines);

        Inquiry::create([
            'name' => $in['name'],
            'email' => $in['email'] ?: 'no-email@asa-lead.filipinohomes.com',
            'message' => $message,
            'source' => 'asa_gpt_seller',
            'device' => ['device_id' => $request->input('device_id'), 'agent' => $request->userAgent(), 'agent_id' => $agent->id],
        ]);
        if ($agentEmail) {
            try {
                Mail::raw($message."\n\nReply to the owner directly. — ASA-GPT, Filipino Homes", function ($m) use ($agentEmail, $agentName, $in) {
                    $m->to($agentEmail, $agentName)->subject("[ASA-GPT] Owner wants to list: {$in['name']}");
                    if (! empty($in['email'])) {
                        $m->replyTo($in['email'], $in['name']);
                    }
                });
            } catch (\Throwable $e) {
                Log::warning('ASA seller lead mail failed', ['agent' => $agent->id, 'error' => $e->getMessage()]);
            }
        }

        $reply = "Your property is with **{$agentName}**. They'll reach you".($in['mobile'] ? " at {$in['mobile']}" : '').($in['email'] ? ($in['mobile'] ? ' or ' : ' at ')."{$in['email']}" : '').' to talk about listing it.';
        $this->appendMessages($request, [[
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'role' => 'assistant',
            'content' => $reply,
        ]]);

        return [
            'message' => $reply,
            'channel' => 'inbox',
            'agent' => ['name' => $agentName, 'mobile' => $agent->user->mobile_no ?? $agent->mobile_no ?? null, 'avatar' => $agent->user->avatar ?? null],
        ];
    }

    /** The most recent valuation panel in this thread, if any. */
    private function latestWorth(Request $request): ?array
    {
        try {
            $found = null;
            foreach ($this->getDailyMessages($request) as $m) {
                $w = $m['metaData']['worth'] ?? null;
                if (is_array($w) && empty($w['empty']) && isset($w['estimate'])) {
                    $found = $w;
                }
            }

            return $found;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * What the buyer asked in this thread and what ASA told them about this listing — the context an
     * agent otherwise never gets.
     *
     * @return array{asked: array<int, string>, context: ?string}
     */
    private function conversationSummary(Request $request, int $listingId): array
    {
        $asked = [];
        $context = null;
        try {
            $messages = $this->getDailyMessages($request);
            foreach ($messages as $m) {
                if (($m['role'] ?? '') === 'user' && is_string($m['content'] ?? null)) {
                    $text = trim($m['content']);
                    if ($text !== '' && ! preg_match('/listing\s*#\d+/i', $text)) {
                        $asked[] = mb_substr($text, 0, 160);
                    }
                }
                $deal = $m['metaData']['deal'] ?? null;
                if (is_array($deal) && (int) ($deal['listing_id'] ?? 0) === $listingId && isset($deal['context'])) {
                    $c = $deal['context'];
                    $context = sprintf('%s vs a typical %s for a %s in %s (%s%% %s typical); verdict: %s.',
                        PriceCommandService::peso((float) $c['price']), PriceCommandService::peso((float) $c['median']), $deal['what'] ?? $c['type'], $c['area'],
                        abs((int) ($c['diff_pct'] ?? 0)), ((int) ($c['diff_pct'] ?? 0)) < 0 ? 'below' : 'above', $deal['verdict'] ?? '');
                }
            }
        } catch (\Throwable) {
            // a lead without the summary is still a lead
        }

        return ['asked' => array_slice(array_values(array_unique($asked)), -4), 'context' => $context];
    }
}
