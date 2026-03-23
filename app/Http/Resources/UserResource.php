<?php
namespace App\Http\Resources;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'name'      => $this->name,
            'email'     => $this->email,
            'mobile_no' => $this->agent->mobile_no ?? "",
            'avatar'    => $this->avatar,
            'role'      => $this->role?->name,
            // 'agent'     => $this->agent ? ['id' => $this->agent->id] : null,
            'created_at' => $this->created_at,
        ];
    }
}
