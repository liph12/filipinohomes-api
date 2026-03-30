<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdCampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $now = Carbon::now('Asia/Manila');
        $startsAt = $this->starts_at;
        $endsAt = $this->ends_at;

        $startsIn = null;
        if ($startsAt && $startsAt->isAfter($now)) {
            $startsIn = (int) ceil($now->diffInDays($startsAt));
        }

        $daysLeft = null;
        if ($endsAt) {
            $daysLeft = $endsAt->isPast() ? 0 : (int) ceil($now->diffInDays($endsAt));
        }

        $isRunning = true;
        if ($startsAt && $startsAt->isAfter($now)) {
            $isRunning = false;
        }
        if ($endsAt && $endsAt->isPast()) {
            $isRunning = false;
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'advertiser' => $this->advertiser,
            'status' => $this->status,
            'starts_at' => $startsAt?->toIso8601String(),
            'ends_at' => $endsAt?->toIso8601String(),
            'days_left' => $daysLeft,
            'starts_in' => $startsIn,
            'is_running' => $isRunning,
            'ads_count' => $this->whenCounted('ads'),
            'ads' => AdResource::collection($this->whenLoaded('ads')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
