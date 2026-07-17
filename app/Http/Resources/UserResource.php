<?php
namespace App\Http\Resources;
use App\Services\TeamLeadershipService;
use App\Support\AvatarUrl;
use App\Support\Impersonation;
use App\Support\OfficeRegionMap;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $lastActive = Carbon::parse($this->active_at ?? $this->updated_at);
        $lastSeen = $lastActive->isToday()
        ? 'Active at ' . $lastActive->format('g:i A')
        : ($lastActive->isYesterday()
            ? 'Yesterday at ' . $lastActive->format('g:i A')
            : $lastActive->format('F j \a\t g:i A'));

        $teamService = app(TeamLeadershipService::class);
        $isTeamLeader = $teamService->isTeamLeader($this->id);

        $data = [
            'id'        => $this->id,
            'name'      => $this->name,
            'email'     => $this->email,
            'mobile_no' => $this->agent->mobile_no ?? $this->mobile_no ?? "",
            // WhatsApp lives only on the agent profile (no users column), so a
            // plain client resolves to null. Email above stays the users-table
            // value for everyone; for an agent participant mobile/WhatsApp come
            // from their agents profile via the eager-loaded `agent` relation.
            'whats_app_no' => $this->agent?->whats_app_no ?? null,
            // Client demographics (null until provided). Age is intentionally
            // NOT exposed — birthdate is present and age is a trivial
            // frontend computation.
            'birthdate' => $this->birthdate,
            'gender'    => $this->gender,
            'avatar'    => AvatarUrl::clean($this->avatar),
            'role'      => $this->role?->name,
            // Office region (from the agent profile) — lets the frontend show a
            // secretary's region and gate region-scoped UI. Null for users with
            // no agent profile / no region.
            'region'       => $this->agent?->region,
            'region_label' => $this->agent?->region ? OfficeRegionMap::label($this->agent->region) : null,
            'is_team_leader' => $isTeamLeader,
            'led_member_user_ids' => $isTeamLeader ? $teamService->getLedTeamMemberUserIds($this->id) : [],
            // Team IDs the user leads — used client-side to filter /agents
            // (and similar list endpoints) to "my team only" for the leader
            // sidebar entries. Empty for non-leaders.
            'led_team_ids' => $isTeamLeader ? $teamService->getLedTeamIds($this->id) : [],
            'active_at' => $lastSeen,
            'last_online_at' => $this->last_online_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];

        if ($this->pivot) {
            $data['last_read_at'] = $this->pivot->last_read_at;
        }

        // True only when THIS resource is the current request's authenticated
        // user AND that session is an admin-impersonation token — lets the
        // frontend show the "Return to admin" banner and hide presence UI.
        $currentUser = $request->user();
        $data['is_impersonated'] = $currentUser
            && (int) $currentUser->id === (int) $this->id
            && Impersonation::isImpersonated($currentUser);

        return $data;
    }
}
