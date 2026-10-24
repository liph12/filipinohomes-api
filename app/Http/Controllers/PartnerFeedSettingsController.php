<?php

namespace App\Http\Controllers;

use App\Services\Listing\PartnerFeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin settings for the partner API — System Settings → "Partner Listings
 * Feed". The endpoints are ExternalListingController (all listings) and
 * AgentController::showByEmail (one agent's listings).
 */
class PartnerFeedSettingsController extends Controller
{
    public function __construct(private PartnerFeedService $feed) {}

    public function show(): JsonResponse
    {
        return response()->json($this->state());
    }

    public function update(Request $request): JsonResponse
    {
        $rules = [];
        foreach (PartnerFeedService::FEEDS as $feed) {
            $rules += [
                "{$feed}"            => 'required|array',
                "{$feed}.enabled"    => 'required|boolean',
                "{$feed}.audit"      => 'present|array',
                "{$feed}.audit.*"    => ['string', Rule::in(array_keys(PartnerFeedService::AUDIT))],
                "{$feed}.statuses"   => 'present|array',
                "{$feed}.statuses.*" => ['string', Rule::in(array_keys(PartnerFeedService::STATUSES))],
            ];
        }
        $data = $request->validate($rules);

        $this->feed->save($data);

        return response()->json($this->state());
    }

    private function state(): array
    {
        $pairs = fn (array $labels) => collect($labels)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all();

        return [
            'settings' => $this->feed->settings(),
            'options'  => ['audit' => $pairs(PartnerFeedService::AUDIT), 'statuses' => $pairs(PartnerFeedService::STATUSES)],
            'counts'   => [
                'listings'     => $this->feed->counts(true),
                'agent_lookup' => $this->feed->counts(false),
            ],
            'endpoints' => [
                'listings'     => rtrim((string) config('app.url'), '/').'/api/fh-agent/listings',
                'agent_lookup' => rtrim((string) config('app.url'), '/').'/api/fh-agent/{email}',
            ],
        ];
    }
}
