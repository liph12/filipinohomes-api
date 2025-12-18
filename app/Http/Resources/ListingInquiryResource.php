<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\UserResource;
use App\Http\Resources\ListingResource;
use App\Http\Resources\ListingConversationResource;

class ListingInquiryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'status'        => $this->status,
            'client'        => new UserResource($this->client),
            'listing'       => new ListingResource($this->listing),
            'conversation'  => new ListingConversationResource($this->listingConversation)
        ];
    }
}
