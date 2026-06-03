<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'version'      => $this->version,
            'platform'     => $this->platform,
            'download_url' => $this->download_url,
            'notes'        => $this->notes,
            'is_latest'    => $this->is_latest,
            'released_at'  => $this->released_at,
            'created_at'   => $this->created_at,
        ];
    }
}
