<?php
namespace App\Http\Resources;
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

        $data = [
            'id'        => $this->id,
            'name'      => $this->name,
            'email'     => $this->email,
            'mobile_no' => $this->agent->mobile_no ?? $this->mobile_no ?? "",
            'avatar'    => $this->avatar,
            'role'      => $this->role?->name,
            'active_at' => $lastSeen,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'active_at_raw' => $lastActive
        ];

        if ($this->pivot) {
            $data['last_read_at'] = $this->pivot->last_read_at;
        }

        return $data;
    }
}
