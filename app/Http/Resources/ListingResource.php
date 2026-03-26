<?php
namespace App\Http\Resources;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\AgentResource;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\PropertyResource;
class ListingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'code'           => $this->code,
            'visibility'     => $this->visibility,
            'name'           => $this->name,
            'slug'           => $this->slug,
            'price'          => $this->price,
            'featured_photo' => $this->featured_photo,
            'is_featured'    => $this->is_featured,
            'clicks'         => $this->clicks,
            'seo_tags'       => $this->seo_tags,
            'created_at'     => $this->created_at,
            'updated_at'     => $this->updated_at->diffForHumans(),
            'property'       => new PropertyResource($this->property),
            'category'       => new CategoryResource($this->category),
            'agent'          => new AgentResource($this->agent)
        ];
    }
}
